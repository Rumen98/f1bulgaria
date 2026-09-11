/**
 * Материалите на постройките: публика по трибуните, фасади на OSM сградите и
 * процедурните „бетонни" детайлни карти за стени/пит/тунел.
 *
 * ЗАЩО отделен модул: mesh.js (трибуни, OSM сгради) и decor.js (пит стена,
 * градски мантинели, тунел) искат едни и същи текстури. Тук те се строят
 * веднъж, детерминирано (hashNoise, не Math.random — без мъждукане при
 * презареждане) и без външни файлове: canvas за цветните карти, DataTexture
 * за нормалите (работи и в node — тестовете строят декора без браузър).
 *
 * Споделените карти (makeDetailMaps) са модулни singleton-и: Game.dispose ги
 * dispose-ва заедно с материала, а three ги качва отново при следващата игра —
 * същото поведение като noiseTex.js.
 */

import * as THREE from 'three';
import { applyPatch } from './materialPatch.js';

/** Цветове по подразбиране — същите като COLORS в mesh.js (без импорт: цикъл). */
const DEFAULT_BUILDING = 0x8d8579;
const DEFAULT_ROOF = 0x5a5852;

/** Метри публика, които покрива едно повторение на текстурата (20 седалки × 0.5 m). */
export const CROWD_METRES_PER_REPEAT = 10;

/** Метри фасада на едно повторение на плочката (един прозорец на етаж). */
const FACADE_TILE_METRES = 4;

/** Метри на повторението на емисивната карта (4×4 прозореца, 35 % светят). */
const WINDOW_MAP_METRES = 16;

/** Кеш на споделените детайлни карти (един комплект за сесията). */
let detailMaps = null;

/**
 * Текстура на публика: 20 седалки × 20 реда върху 512², със седалкова стъпка
 * и хора (елипса рамене + глава) на 85 % от местата. При repeat, изведен от
 * crowdRepeat(), един човек е ≈0.5 m — от 30 m трибуната се чете като хора,
 * не като шум (старата карта беше 4600 точки по 3 cm).
 *
 * @param {number|null} accent Доминиращ цвят на публиката (тифозите, оранжевата армия)
 * @returns {THREE.CanvasTexture}
 */
export function makeCrowdTexture(accent = null) {
    const size = 512;
    const seats = 20;
    const rows = 20;
    const cell = size / seats;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');

    const shirts = ['#a93f45', '#d8d4ca', '#416b9a', '#b89a3e', '#447a5c', '#765486', '#a8693e', '#e4e1d8', '#41464d', '#294d73', '#252b32'];
    const skins = ['#f0c9aa', '#d9aa83', '#bd825d', '#925b3f', '#70442f', '#e7b995'];
    const hair = ['#1c1715', '#33251f', '#5a4332', '#8a6847', '#c2aa82', '#342f2c'];
    const accentCss = accent === null ? null : '#' + accent.toString(16).padStart(6, '0');
    ctx.imageSmoothingEnabled = true;

    for (let r = 0; r < rows; r++) {
        const y0 = r * cell;
        // Всеки ред има дълбока сянка, отделни седалки и бетонен челен ръб.
        // Това дава реална перспектива в mip-овете, вместо равен цветен шум.
        ctx.fillStyle = r % 2 === 0 ? '#30353d' : '#343942';
        ctx.fillRect(0, y0, size, cell);

        for (let s = 0; s < seats; s++) {
            const x0 = s * cell;
            const seed = r * 131 + s * 17;
            const occupied = hashNoise(seed * 1.31) <= 0.84;

            // Извита седалка и тънка междина между местата.
            ctx.fillStyle = occupied ? '#3f4651' : '#505966';
            ctx.beginPath();
            ctx.roundRect(x0 + cell * 0.13, y0 + cell * 0.31, cell * 0.74, cell * 0.48, cell * 0.08);
            ctx.fill();
            ctx.fillStyle = 'rgba(8, 10, 14, 0.35)';
            ctx.fillRect(x0 + cell * 0.12, y0 + cell * 0.73, cell * 0.76, cell * 0.08);

            if (!occupied) {
                continue;
            }

            const shirt =
                accentCss !== null && hashNoise(seed * 5.13) < 0.32
                    ? accentCss
                    : shirts[Math.floor(hashNoise(seed * 1.37) * shirts.length)];
            const skin = skins[Math.floor(hashNoise(seed * 2.71) * skins.length)];
            const hairColor = hair[Math.floor(hashNoise(seed * 9.1) * hair.length)];
            const cx = x0 + cell * 0.5 + (hashNoise(seed * 3.3) - 0.5) * cell * 0.14;
            const lean = (hashNoise(seed * 7.7) - 0.5) * cell * 0.1;
            const stature = 0.9 + hashNoise(seed * 4.7) * 0.16;
            const headX = cx + lean;
            const headY = y0 + cell * (0.35 - (stature - 0.9) * 0.15);
            const shoulderY = y0 + cell * 0.5;
            const shoulder = cell * (0.25 + hashNoise(seed * 6.1) * 0.06);
            const waist = shoulder * (0.58 + hashNoise(seed * 8.3) * 0.12);

            // Мека сянка зад тялото го отделя от седалката при средна дистанция.
            ctx.fillStyle = 'rgba(8, 9, 12, 0.3)';
            ctx.beginPath();
            ctx.ellipse(cx + cell * 0.03, y0 + cell * 0.66, shoulder * 1.08, cell * 0.24, 0, 0, Math.PI * 2);
            ctx.fill();

            // Торс с рамене и стеснение към кръста вместо еднаква елипса.
            ctx.fillStyle = shirt;
            ctx.beginPath();
            ctx.moveTo(cx - waist, y0 + cell * 0.8);
            ctx.lineTo(cx - shoulder, shoulderY + cell * 0.05);
            ctx.quadraticCurveTo(cx - shoulder * 0.72, shoulderY - cell * 0.04, cx - cell * 0.09, shoulderY - cell * 0.04);
            ctx.lineTo(cx + cell * 0.09, shoulderY - cell * 0.04);
            ctx.quadraticCurveTo(cx + shoulder * 0.72, shoulderY - cell * 0.04, cx + shoulder, shoulderY + cell * 0.05);
            ctx.lineTo(cx + waist, y0 + cell * 0.8);
            ctx.closePath();
            ctx.fill();

            // Ръце: малка част от публиката ръкопляска или снима. Това е само
            // в общата текстура — нула допълнителни обекти или draw calls.
            const pose = hashNoise(seed * 10.9);
            if (pose > 0.86) {
                ctx.strokeStyle = shirt;
                ctx.lineWidth = cell * 0.12;
                ctx.lineCap = 'round';
                ctx.beginPath();
                ctx.moveTo(cx - shoulder * 0.72, shoulderY + cell * 0.05);
                ctx.lineTo(headX - cell * 0.2, headY - cell * 0.17);
                ctx.moveTo(cx + shoulder * 0.72, shoulderY + cell * 0.05);
                ctx.lineTo(headX + cell * 0.2, headY - cell * 0.14);
                ctx.stroke();
                ctx.fillStyle = skin;
                for (const handX of [headX - cell * 0.2, headX + cell * 0.2]) {
                    ctx.beginPath();
                    ctx.arc(handX, headY - cell * 0.17, cell * 0.055, 0, Math.PI * 2);
                    ctx.fill();
                }
            }

            // Врат, овална глава, уши и дискретна светла страна на лицето.
            ctx.fillStyle = skin;
            ctx.fillRect(headX - cell * 0.055, headY + cell * 0.1, cell * 0.11, cell * 0.1);
            ctx.fillStyle = skin;
            ctx.beginPath();
            ctx.ellipse(headX, headY, cell * 0.135, cell * (0.16 + hashNoise(seed * 12.1) * 0.025), lean * 0.12, 0, Math.PI * 2);
            ctx.fill();
            ctx.fillStyle = 'rgba(255, 238, 220, 0.18)';
            ctx.beginPath();
            ctx.ellipse(headX - cell * 0.035, headY - cell * 0.01, cell * 0.045, cell * 0.09, 0, 0, Math.PI * 2);
            ctx.fill();

            // Косата има три евтини силуета: шапка, къса коса или по-обемен
            // контур. Малките вариации разбиват повторението на атласа.
            const style = hashNoise(seed * 14.3);
            ctx.fillStyle = hairColor;
            if (style > 0.82) {
                ctx.fillRect(headX - cell * 0.15, headY - cell * 0.13, cell * 0.3, cell * 0.065);
                ctx.fillRect(headX + cell * 0.08, headY - cell * 0.085, cell * 0.13, cell * 0.035);
            } else {
                ctx.beginPath();
                ctx.ellipse(headX, headY - cell * 0.075, cell * (style < 0.25 ? 0.15 : 0.135), cell * (style < 0.25 ? 0.105 : 0.075), 0, Math.PI, Math.PI * 2);
                ctx.fill();
            }
        }

        // Ръбът остава пред краката/седалките и дава реално наслояване.
        ctx.fillStyle = '#73777d';
        ctx.fillRect(0, y0 + cell * 0.82, size, cell * 0.18);
        ctx.fillStyle = 'rgba(245, 245, 240, 0.15)';
        ctx.fillRect(0, y0 + cell * 0.82, size, Math.max(1, cell * 0.035));
    }

    const texture = new THREE.CanvasTexture(canvas);
    texture.wrapS = THREE.RepeatWrapping;
    texture.wrapT = THREE.RepeatWrapping;
    texture.colorSpace = THREE.SRGBColorSpace;
    texture.name = 'crowd-atlas';

    return texture;
}

/**
 * Повторението на текстурата на публиката за лице с дадени размери — 0.5 m
 * на човек.
 *
 * @param {number} widthMetres
 * @param {number} heightMetres
 * @returns {[number, number]}
 */
export function crowdRepeat(widthMetres, heightMetres) {
    return [widthMetres / CROWD_METRES_PER_REPEAT, heightMetres / CROWD_METRES_PER_REPEAT];
}

/**
 * Материал за OSM сградите: фасадна плочка 4×4 m (мазилка + прозорец + корниз)
 * с метрични UV (ExtrudeGeometry дава координати в метри → repeat 1/4), покрив
 * в отделен цвят по нормалата (кръпка през materialPatch, без втори материал)
 * и нощем емисивна карта с 35 % светещи прозорци.
 *
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @param {{night?: boolean, look?: object, lowPower?: boolean}} [options]
 * @returns {THREE.MeshStandardMaterial}
 */
export function buildingMaterial(circuit, options = {}) {
    const look = options.look ?? circuit.look ?? {};
    const facade = look.facade ?? {};
    const night = options.night === true;
    const color = facade.colour ?? circuit.facadeColor ?? DEFAULT_BUILDING;

    const map = makeFacadeTile();
    map.repeat.set(1 / FACADE_TILE_METRES, 1 / FACADE_TILE_METRES);

    const material = new THREE.MeshStandardMaterial({
        map,
        color,
        metalness: 0,
        roughness: 0.8,
    });

    // Прозорците светят само нощем и само ако визията на пистата ги иска.
    if (night && facade.windows !== false) {
        const emissiveMap = makeWindowMap();
        emissiveMap.repeat.set(1 / WINDOW_MAP_METRES, 1 / WINDOW_MAP_METRES);
        material.emissiveMap = emissiveMap;
        material.emissive = new THREE.Color(0xffffff);
        material.emissiveIntensity = 0.9;
    }

    // Покривът: там, където световната нормала сочи нагоре, плочката се
    // заменя с плътен цвят и емисията гасне (иначе прозорци по покривите).
    applyPatch(material, {
        name: 'facadeRoof',
        uniforms: { uRoofColor: { value: new THREE.Color(facade.roof ?? DEFAULT_ROOF) } },
        vertexHead: 'varying float vRoof;',
        vertexMain: 'vRoof = step(0.85, normalize((modelMatrix * vec4(objectNormal, 0.0)).xyz).y);',
        fragmentHead: 'varying float vRoof;\nuniform vec3 uRoofColor;',
        replace: [
            ['map_fragment', '#include <map_fragment>\n\tdiffuseColor.rgb = mix(diffuseColor.rgb, uRoofColor, vRoof);'],
            ['emissivemap_fragment', '#include <emissivemap_fragment>\n\ttotalEmissiveRadiance *= 1.0 - vRoof;'],
        ],
    });

    return material;
}

/**
 * Споделените бетонни детайлни карти: 256² нормала (Собел на value noise) +
 * грапавост в G канала (three чете roughnessMap.g). Метрични: една плочка на
 * 2 m при uv в метри/2 (wallGeometry с uvTile 2). Singleton.
 *
 * @returns {{normalMap: THREE.DataTexture, roughnessMap: THREE.DataTexture}}
 */
export function makeDetailMaps() {
    if (detailMaps) {
        return detailMaps;
    }

    const size = 256;
    const height = noiseField(size, 6, 4, 0.55, 17);
    const normalMap = normalFromHeight(height, size, 1.0);

    const rough = new Uint8Array(size * size * 4);
    for (let i = 0; i < size * size; i++) {
        // Порите на бетона са по-грапави от гладките петна: 0.72..0.97.
        const value = 0.72 + height[i] * 0.25;
        const byte = Math.round(value * 255);
        rough[i * 4] = byte;
        rough[i * 4 + 1] = byte;
        rough[i * 4 + 2] = byte;
        rough[i * 4 + 3] = 255;
    }
    const roughnessMap = new THREE.DataTexture(rough, size, size, THREE.RGBAFormat);
    configureTileable(roughnessMap);

    detailMaps = { normalMap, roughnessMap };

    return detailMaps;
}

/**
 * Безшевна нормална карта от value noise — гумата на стековете, грапавата
 * мазилка. Не се кешира: всеки консуматор има своя скала.
 *
 * @param {{size?: number, cells?: number, octaves?: number, strength?: number, seed?: number}} [options]
 * @returns {THREE.DataTexture}
 */
export function makeNoiseNormalMap(options = {}) {
    const size = options.size ?? 128;
    const height = noiseField(size, options.cells ?? 8, options.octaves ?? 3, 0.5, options.seed ?? 3);

    return normalFromHeight(height, size, options.strength ?? 1.0);
}

// ── Плочки (canvas) ──────────────────────────────────────────────────────

/**
 * Фасадна плочка 4×4 m: мазилка с петна, прозорец 1.4×1.8 m с рамка и корниз
 * (плоча на етажа) по горния ръб.
 *
 * @returns {THREE.CanvasTexture}
 */
function makeFacadeTile() {
    const size = 128;
    const ppm = size / FACADE_TILE_METRES;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');

    // Плочката е бяла-неутрална: цветът на сградата идва от material.color.
    ctx.fillStyle = '#e8e6e0';
    ctx.fillRect(0, 0, size, size);
    for (let i = 0; i < 260; i++) {
        const shade = hashNoise(i * 1.91);
        ctx.fillStyle = shade > 0.5 ? `rgba(255,255,255,${0.15 + shade * 0.2})` : `rgba(90,84,74,${0.08 + shade * 0.18})`;
        ctx.fillRect(hashNoise(i * 2.71) * size, hashNoise(i * 3.37) * size, 3, 2);
    }

    // Корниз/етажна плоча по горния ръб.
    ctx.fillStyle = '#b8b3a8';
    ctx.fillRect(0, 0, size, 3 * (ppm / 8));
    ctx.fillStyle = 'rgba(0,0,0,0.18)';
    ctx.fillRect(0, 3 * (ppm / 8), size, 2);

    // Прозорец 1.4×1.8 m, центриран, с рамка и перваз.
    const w = 1.4 * ppm;
    const h = 1.8 * ppm;
    const x = (size - w) / 2;
    const y = size * 0.3;
    ctx.fillStyle = '#c8c4bb';
    ctx.fillRect(x - 3, y - 3, w + 6, h + 6);
    ctx.fillStyle = '#3a4652';
    ctx.fillRect(x, y, w, h);
    ctx.fillStyle = 'rgba(180,200,220,0.35)';
    ctx.fillRect(x, y, w * 0.5, h * 0.5);
    ctx.fillStyle = '#c8c4bb';
    ctx.fillRect(x + w / 2 - 1, y, 2, h);
    ctx.fillRect(x, y + h / 2 - 1, w, 2);
    ctx.fillStyle = '#9d988e';
    ctx.fillRect(x - 5, y + h + 3, w + 10, 3);

    const texture = new THREE.CanvasTexture(canvas);
    texture.wrapS = THREE.RepeatWrapping;
    texture.wrapT = THREE.RepeatWrapping;
    texture.colorSpace = THREE.SRGBColorSpace;

    return texture;
}

/**
 * Емисивна карта на прозорците: 16×16 m (4×4 прозореца на същата решетка
 * като плочката), 35 % светят топло с различна сила.
 *
 * @returns {THREE.CanvasTexture}
 */
function makeWindowMap() {
    const size = 512;
    const cells = WINDOW_MAP_METRES / FACADE_TILE_METRES;
    const cell = size / cells;
    const ppm = cell / FACADE_TILE_METRES;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');

    ctx.fillStyle = '#000000';
    ctx.fillRect(0, 0, size, size);

    const w = 1.4 * ppm;
    const h = 1.8 * ppm;
    for (let r = 0; r < cells; r++) {
        for (let c = 0; c < cells; c++) {
            const seed = r * 19 + c * 7 + 3;
            if (hashNoise(seed * 1.13) > 0.35) {
                continue;
            }
            const warmth = hashNoise(seed * 2.9);
            const x = c * cell + (cell - w) / 2;
            const y = r * cell + cell * 0.3;
            ctx.fillStyle = warmth > 0.5 ? '#ffd9a0' : '#ffe9c8';
            ctx.globalAlpha = 0.6 + hashNoise(seed * 4.7) * 0.4;
            ctx.fillRect(x, y, w, h);
        }
    }
    ctx.globalAlpha = 1;

    const texture = new THREE.CanvasTexture(canvas);
    texture.wrapS = THREE.RepeatWrapping;
    texture.wrapT = THREE.RepeatWrapping;
    texture.colorSpace = THREE.SRGBColorSpace;

    return texture;
}

// ── Шум и нормали (DataTexture, без DOM) ─────────────────────────────────

/**
 * Безшевно value noise поле в [0,1] с няколко октави.
 *
 * @param {number} size
 * @param {number} cells Клетки на решетката в първата октава
 * @param {number} octaves
 * @param {number} gain
 * @param {number} seed
 * @returns {Float32Array}
 */
function noiseField(size, cells, octaves, gain, seed) {
    const out = new Float32Array(size * size);
    let amplitude = 1;
    let frequency = cells;
    let total = 0;

    for (let o = 0; o < octaves; o++) {
        const lattice = frequency;
        for (let y = 0; y < size; y++) {
            const fy = (y / size) * lattice;
            const iy = Math.floor(fy);
            const ty = smooth(fy - iy);
            for (let x = 0; x < size; x++) {
                const fx = (x / size) * lattice;
                const ix = Math.floor(fx);
                const tx = smooth(fx - ix);

                const v00 = latticeValue(ix, iy, lattice, seed + o * 101);
                const v10 = latticeValue(ix + 1, iy, lattice, seed + o * 101);
                const v01 = latticeValue(ix, iy + 1, lattice, seed + o * 101);
                const v11 = latticeValue(ix + 1, iy + 1, lattice, seed + o * 101);
                const a = v00 + (v10 - v00) * tx;
                const b = v01 + (v11 - v01) * tx;
                out[y * size + x] += (a + (b - a) * ty) * amplitude;
            }
        }
        total += amplitude;
        amplitude *= gain;
        frequency *= 2;
    }

    for (let i = 0; i < out.length; i++) {
        out[i] /= total;
    }

    return out;
}

/** Стойност на възел от решетката, увита по модул → безшевна плочка. */
function latticeValue(ix, iy, lattice, seed) {
    const wx = ((ix % lattice) + lattice) % lattice;
    const wy = ((iy % lattice) + lattice) % lattice;

    return hashNoise(wx * 157.31 + wy * 313.97 + seed * 7.13);
}

/**
 * Нормална карта (tangent space, RGB = 0.5 + n·0.5) от височинно поле със
 * Собел и увиване по ръбовете.
 *
 * @param {Float32Array} height
 * @param {number} size
 * @param {number} strength
 * @returns {THREE.DataTexture}
 */
function normalFromHeight(height, size, strength) {
    const data = new Uint8Array(size * size * 4);
    const at = (x, y) => height[(((y % size) + size) % size) * size + (((x % size) + size) % size)];

    for (let y = 0; y < size; y++) {
        for (let x = 0; x < size; x++) {
            const dx =
                (at(x + 1, y - 1) + 2 * at(x + 1, y) + at(x + 1, y + 1)) -
                (at(x - 1, y - 1) + 2 * at(x - 1, y) + at(x - 1, y + 1));
            const dy =
                (at(x - 1, y + 1) + 2 * at(x, y + 1) + at(x + 1, y + 1)) -
                (at(x - 1, y - 1) + 2 * at(x, y - 1) + at(x + 1, y - 1));
            // Височината е в [0,1] върху size пиксела; мащабът на наклона е
            // произволен — strength го настройва визуално.
            const nx = -dx * strength * 4;
            const ny = -dy * strength * 4;
            const len = Math.hypot(nx, ny, 1);
            const o = (y * size + x) * 4;
            data[o] = Math.round((nx / len * 0.5 + 0.5) * 255);
            data[o + 1] = Math.round((ny / len * 0.5 + 0.5) * 255);
            data[o + 2] = Math.round((1 / len * 0.5 + 0.5) * 255);
            data[o + 3] = 255;
        }
    }

    const texture = new THREE.DataTexture(data, size, size, THREE.RGBAFormat);
    configureTileable(texture);

    return texture;
}

/** DataTexture по подразбиране е Nearest без mipmap-и — за плочка искаме обратното. */
function configureTileable(texture) {
    texture.wrapS = THREE.RepeatWrapping;
    texture.wrapT = THREE.RepeatWrapping;
    texture.magFilter = THREE.LinearFilter;
    texture.minFilter = THREE.LinearMipmapLinearFilter;
    texture.generateMipmaps = true;
    texture.colorSpace = THREE.NoColorSpace;
    texture.needsUpdate = true;
}

/** Гладка S-крива върху [0,1]. */
function smooth(t) {
    return t * t * (3 - 2 * t);
}

/**
 * Детерминиран шум в [0,1) от число (същият като в decor.js/mesh.js).
 *
 * @param {number} n
 * @returns {number}
 */
function hashNoise(n) {
    const x = Math.sin(n * 12.9898) * 43758.5453;

    return x - Math.floor(x);
}
