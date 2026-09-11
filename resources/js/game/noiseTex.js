/**
 * Споделени шумови текстури за шейдърите: анти-тайлинг на настилките, heat
 * haze, облачни сенки, дитър, трептене на меките частици.
 *
 * Генерират се процедурно ВЕДНЪЖ на страница и се кешират на ниво модул.
 * Всяка Game инстанция е нов WebGL контекст и three качва DataTexture-а
 * наново при първата употреба — CPU данните са същите, затова кешът
 * надживява играта. НИКОЙ не ги dispose-ва: dispose би махнал само GPU
 * копието, а при следващия кадър three пак би го качило.
 *
 * Детерминирани (seed по канал/октава) — една и съща картина при всяко
 * зареждане, без Math.random(). Модулът не пипа window/document — зарежда
 * се и в Node (селфтестове).
 */

import * as THREE from 'three';

/** @type {Map<string, THREE.DataTexture>} */
const cache = new Map();

/** Таван на getBlueNoise — виж коментара там за цената. */
const BLUE_NOISE_MAX_SIZE = 64;

/**
 * Тайлващ се value noise (fbm, 3 октави): RGBA, четири НЕЗАВИСИМИ канала в
 * [0, 1] със средна ≈ 0.5. RepeatWrapping, линейно филтриране + mipmaps —
 * размерът е степен на двойката и шумът е безшевен, така че и мип нивата са
 * безшевни (сампъл от разстояние не алиасва).
 *
 * @param {number} [size=256] Степен на двойката (64, 128, 256…)
 * @returns {THREE.DataTexture}
 */
export function getNoiseTexture(size = 256) {
    assertPowerOfTwo(size, 'getNoiseTexture');
    const key = `value:${size}`;
    let texture = cache.get(key);
    if (!texture) {
        texture = new THREE.DataTexture(valueNoiseData(size), size, size, THREE.RGBAFormat, THREE.UnsignedByteType);
        texture.name = `noise-value-${size}`;
        texture.wrapS = THREE.RepeatWrapping;
        texture.wrapT = THREE.RepeatWrapping;
        texture.magFilter = THREE.LinearFilter;
        texture.minFilter = THREE.LinearMipmapLinearFilter;
        texture.generateMipmaps = true;
        texture.needsUpdate = true;
        cache.set(key, texture);
    }

    return texture;
}

/**
 * Синьо-шумова текстура (void-and-cluster върху тор): RGBA, четири независими
 * канала с равномерно разпределени рангове в [0, 1]. Семплира се ПО ПИКСЕЛ
 * (gl_FragCoord.xy / size с RepeatWrapping) и с NearestFilter — линейното
 * филтриране и mipmaps биха унищожили спектъра, който е целият смисъл.
 * Генерирането е O(size⁴) на главната нишка (линейно търсене на минимума
 * за всеки поставен пиксел): ~140 ms общо при 64², ~2 s при 128², ~36 s при
 * 256². Затова размерът е ограничен до 64 и е lazy: първият консуматор
 * плаща веднъж за страницата — и трябва да е при зареждане, не в кадър.
 *
 * @param {number} [size=64] Степен на двойката, най-много 64
 * @returns {THREE.DataTexture}
 */
export function getBlueNoise(size = 64) {
    assertPowerOfTwo(size, 'getBlueNoise');
    if (size > BLUE_NOISE_MAX_SIZE) {
        throw new RangeError(`getBlueNoise: най-много ${BLUE_NOISE_MAX_SIZE} (O(size⁴) генериране), получен ${size}`);
    }
    const key = `blue:${size}`;
    let texture = cache.get(key);
    if (!texture) {
        texture = new THREE.DataTexture(blueNoiseData(size), size, size, THREE.RGBAFormat, THREE.UnsignedByteType);
        texture.name = `noise-blue-${size}`;
        texture.wrapS = THREE.RepeatWrapping;
        texture.wrapT = THREE.RepeatWrapping;
        texture.magFilter = THREE.NearestFilter;
        texture.minFilter = THREE.NearestFilter;
        texture.generateMipmaps = false;
        texture.needsUpdate = true;
        cache.set(key, texture);
    }

    return texture;
}

/**
 * @param {number} size
 * @param {string} caller
 */
function assertPowerOfTwo(size, caller) {
    if (!Number.isInteger(size) || size < 2 || (size & (size - 1)) !== 0) {
        throw new RangeError(`${caller}: размерът трябва да е степен на двойката, получен ${size}`);
    }
}

/**
 * Четири канала fbm value noise → RGBA8.
 *
 * @param {number} size
 * @returns {Uint8Array}
 */
function valueNoiseData(size) {
    const pixels = size * size;
    const data = new Uint8Array(pixels * 4);
    const channel = new Float32Array(pixels);

    for (let c = 0; c < 4; c++) {
        channel.fill(0);
        let total = 0;
        for (let octave = 0; octave < 3; octave++) {
            // 256 px → решетки 16/32/64 клетки (16/8/4 px на клетка).
            const cells = Math.max(2, (size >> 4) << octave);
            const amplitude = 1 / (1 << octave);
            addValueOctave(channel, size, cells, amplitude, hashString(`value:${size}:${c}:${octave}`));
            total += amplitude;
        }
        for (let i = 0; i < pixels; i++) {
            data[i * 4 + c] = Math.round((channel[i] / total) * 255);
        }
    }

    return data;
}

/**
 * Една октава: случайна решетка (тороидална) с квинтична интерполация —
 * класическият value noise на Перлин, тайлващ се по конструкция.
 *
 * @param {Float32Array} out
 * @param {number} size
 * @param {number} cells
 * @param {number} amplitude
 * @param {number} seed
 */
function addValueOctave(out, size, cells, amplitude, seed) {
    const rand = mulberry32(seed);
    const lattice = new Float32Array(cells * cells);
    for (let i = 0; i < lattice.length; i++) {
        lattice[i] = rand();
    }

    const scale = cells / size;
    for (let y = 0; y < size; y++) {
        const fy = y * scale;
        const y0 = Math.floor(fy);
        const y1 = (y0 + 1) % cells;
        const ty = fade(fy - y0);
        for (let x = 0; x < size; x++) {
            const fx = x * scale;
            const x0 = Math.floor(fx);
            const x1 = (x0 + 1) % cells;
            const tx = fade(fx - x0);
            const top = lerp(lattice[y0 * cells + x0], lattice[y0 * cells + x1], tx);
            const bottom = lerp(lattice[y1 * cells + x0], lattice[y1 * cells + x1], tx);
            out[y * size + x] += amplitude * lerp(top, bottom, ty);
        }
    }
}

/**
 * Четири независими синьо-шумови канала → RGBA8.
 *
 * @param {number} size
 * @returns {Uint8Array}
 */
function blueNoiseData(size) {
    const pixels = size * size;
    const data = new Uint8Array(pixels * 4);
    const ranks = new Uint16Array(pixels);

    for (let c = 0; c < 4; c++) {
        blueNoiseRanks(ranks, size, hashString(`blue:${size}:${c}`));
        for (let i = 0; i < pixels; i++) {
            data[i * 4 + c] = Math.round((ranks[i] / (pixels - 1)) * 255);
        }
    }

    return data;
}

/**
 * Прогресивно void-and-cluster: всеки следващ пиксел е този с най-малка
 * натрупана енергия (най-голямата „празнина"), после енергията му (гаусово
 * ядро σ = 1.5, тороидално) се добавя към съседите. Рангът на пиксела е
 * редът на поставяне — при всеки праг множеството е синьо-шумово.
 * Ядрото е отрязано на радиус 6 (там тежестта е < 3·10⁻⁴).
 *
 * @param {Uint16Array} ranks Изход: ранг на всеки пиксел
 * @param {number} size
 * @param {number} seed
 */
function blueNoiseRanks(ranks, size, seed) {
    const pixels = size * size;
    const energy = new Float32Array(pixels);
    const placed = new Uint8Array(pixels);
    const rand = mulberry32(seed);
    // Ситен шум чупи равенствата в началото — иначе първите точки лягат по
    // решетка и спектърът носи периодичност.
    for (let i = 0; i < pixels; i++) {
        energy[i] = rand() * 1e-3;
    }

    const radius = 6;
    const span = radius * 2 + 1;
    const kernel = new Float32Array(span * span);
    const sigma = 1.5;
    for (let dy = -radius; dy <= radius; dy++) {
        for (let dx = -radius; dx <= radius; dx++) {
            kernel[(dy + radius) * span + (dx + radius)] = Math.exp(-(dx * dx + dy * dy) / (2 * sigma * sigma));
        }
    }

    for (let rank = 0; rank < pixels; rank++) {
        let best = -1;
        let bestEnergy = Infinity;
        for (let i = 0; i < pixels; i++) {
            if (placed[i] === 0 && energy[i] < bestEnergy) {
                bestEnergy = energy[i];
                best = i;
            }
        }
        placed[best] = 1;
        ranks[best] = rank;

        const px = best % size;
        const py = (best - px) / size;
        for (let dy = -radius; dy <= radius; dy++) {
            const row = (((py + dy) % size) + size) % size;
            for (let dx = -radius; dx <= radius; dx++) {
                const col = (((px + dx) % size) + size) % size;
                energy[row * size + col] += kernel[(dy + radius) * span + (dx + radius)];
            }
        }
    }
}

/**
 * Квинтична гладка стъпка (Перлин): нулева първа И втора производна в краищата.
 *
 * @param {number} t
 * @returns {number}
 */
function fade(t) {
    return t * t * t * (t * (t * 6 - 15) + 10);
}

/**
 * @param {number} a
 * @param {number} b
 * @param {number} t
 * @returns {number}
 */
function lerp(a, b, t) {
    return a + (b - a) * t;
}

/**
 * Детерминиран PRNG (mulberry32) — копие на този от Game.js, за да няма
 * зависимост към оркестратора.
 *
 * @param {number} seed
 * @returns {() => number} [0, 1)
 */
function mulberry32(seed) {
    let a = seed >>> 0;

    return () => {
        a = (a + 0x6d2b79f5) >>> 0;
        let t = a;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

/**
 * FNV-1a хеш на низ → seed.
 *
 * @param {string} value
 * @returns {number}
 */
function hashString(value) {
    let hash = 2166136261;

    for (let i = 0; i < value.length; i++) {
        hash ^= value.charCodeAt(i);
        hash = Math.imul(hash, 16777619);
    }

    return hash >>> 0;
}
