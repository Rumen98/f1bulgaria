/**
 * Фонови силуети на хоризонта: ридове, гора, скайлайн, дюни или море на
 * 900-1500 m от трасето. Две задачи: (1) идентичност — Спа е долина между
 * хълмове, Баку е град, Зандвоорт е дюни; (2) да скрият ръба на терена и
 * правата линия, където плоската земя среща небето.
 *
 * Геометрия: 1-3 неосветени ленти (MeshBasicMaterial, vertex цветове) по
 * заоблен правоъгълник около bounding box-а на трасето — не кръг около
 * центроида: при Спа (2.5 × 1.6 km) кръг с радиус 1 km минава през пистата.
 * Цветовете са предварително смесени към цвета на мъглата по слой (0.55 /
 * 0.72 / 0.86 — въздушна перспектива); най-външният слой е с fog: false,
 * защото на fogFar линейната мъгла го изтрива напълно, а той е този, който
 * трябва да се вижда над мъглата като силует. Вътрешните са с мъгла: така
 * потъват естествено в нея, независимо от модела на мъглата (атмосферният
 * пакет подменя fog chunk-овете глобално — ние не пипаме там).
 *
 * Няма анимация (update е no-op по договор — запазен за облаци/паралакс),
 * няма сенки, ~1-5 k триъгълника. Модулът не пипа document при зареждане;
 * нощните прозорци са canvas текстура, която в Node просто липсва.
 */

import * as THREE from 'three';

/**
 * Типове фон по подразбиране, когато circuit.look.backdrop липсва (преди
 * атмосферния пакет или за писта без запис): извеждат се от старите полета
 * на CircuitStyle. Експортирани, за да може атмосферата да ги ползва като
 * отправна точка.
 */
export const BACKDROP_DEFAULTS = Object.freeze({
    /** Релеф над това е планинска писта (Спа 55, RBR 65). */
    mountainAmplitude: 30,
    height: Object.freeze({ mountains: 160, treeline: 45, skyline: 90, dunes: 28, sea: 60 }),
    /** Разстояние от bbox-а на трасето до най-външния слой, m. */
    distance: Object.freeze({ min: 900, max: 1500, fogFarRatio: 0.9 }),
    /** Радиуси на слоевете като дял от разстоянието (навътре → навън). */
    layerRatios: Object.freeze([0.62, 0.8, 1.0]),
    /** Колко от цвета на мъглата поема всеки слой. */
    fogMix: Object.freeze([0.55, 0.72, 0.86]),
});

/** Колко под нивото на земята слиза долният ръб — скрива стъпката на терена. */
const BOTTOM_DROP = 5;

/** Огледало на buildGround в mesh.js — нивото на хоризонталната равнина. */
const GROUND = { runoffWidth: 8, runoffDrop: 0.035, sink: 1.4, reliefShare: 0.35 };

/** Нощен скайлайн: метри на една клетка прозорец и клетки в една текстура. */
const WINDOW_METRES = 4;
const WINDOW_CELLS = 16;

/**
 * @typedef {object} BackdropOptions
 * @property {boolean} [lowPower]
 * @property {object} [quality]        game.quality — приема се по договор; фонът е еднакво евтин навсякъде
 * @property {object} [look]           Замества circuit.look
 * @property {number} [groundLevel]    terrain.groundLevel(track, circuit); default — формулата на mesh.buildGround
 * @property {number} [fogColor]       Цвят на мъглата; default look.fog.colour → atmosphere.fogColor
 * @property {boolean} [night]         default look.preset === 'night' → atmosphere.night
 */

/**
 * @typedef {object} Backdrop
 * @property {THREE.Group} group
 * @property {(camera?: THREE.Camera) => void} update
 * @property {() => void} dispose
 * @property {{type: string, layers: number, distance: number}} stats
 */

/**
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @param {BackdropOptions} [options]
 * @returns {Backdrop}
 */
export function buildBackdrop(track, circuit, options = {}) {
    const look = resolveLook(track, circuit, options);
    const group = new THREE.Group();
    group.name = 'backdrop';
    const disposables = [];

    const stats = { type: look.type, layers: 0, distance: look.distance };

    if (look.type !== 'none') {
        const frame = boundsFrame(track);
        const builders = { mountains, treeline, skyline, dunes, sea };
        for (const mesh of builders[look.type](frame, look, disposables)) {
            group.add(mesh);
            stats.layers++;
        }
    }

    let disposed = false;

    return {
        group,
        stats,
        update() {
            // Фонът е статичен; договорът предвижда камера за бъдещ паралакс.
        },
        dispose() {
            if (disposed) {
                return;
            }
            disposed = true;
            for (const resource of disposables) {
                resource.dispose();
            }
            disposables.length = 0;
            group.clear();
        },
    };
}

// ── Look ─────────────────────────────────────────────────────────────────

/**
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @param {BackdropOptions} options
 */
function resolveLook(track, circuit, options) {
    const look = options.look ?? circuit.look ?? {};
    const backdrop = look.backdrop ?? {};
    const type = validType(backdrop.type) ?? inferType(circuit);
    const night = options.night ?? (look.preset === 'night' || circuit.atmosphere?.night === true);
    const fogFar = look.fog?.far ?? circuit.atmosphere?.fogFar ?? 1100;
    const { min, max, fogFarRatio } = BACKDROP_DEFAULTS.distance;

    return {
        type,
        night,
        distance: backdrop.distance ?? Math.min(max, Math.max(min, fogFar * fogFarRatio)),
        height: backdrop.height ?? BACKDROP_DEFAULTS.height[type] ?? 0,
        colour: new THREE.Color(backdrop.colour ?? defaultColour(type, circuit, night)),
        fog: new THREE.Color(options.fogColor ?? look.fog?.colour ?? circuit.atmosphere?.fogColor ?? 0xbcd3e6),
        groundLevel: options.groundLevel ?? groundLevelOf(track, circuit, look.terrain?.relief ?? 1),
        seed: hashString(`backdrop:${track.slug}`),
    };
}

/**
 * @param {unknown} type
 * @returns {string|null}
 */
function validType(type) {
    return typeof type === 'string' && ['mountains', 'treeline', 'skyline', 'dunes', 'sea', 'none'].includes(type)
        ? type
        : null;
}

/**
 * Без look.backdrop: планини при голям релеф, скайлайн за градските писти,
 * дюни при пясъчен терен, гора при дървета, иначе нищо.
 *
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @returns {string}
 */
function inferType(circuit) {
    if ((circuit.terrain?.amplitude ?? 0) > BACKDROP_DEFAULTS.mountainAmplitude) {
        return 'mountains';
    }
    if (circuit.streetWalls) {
        return 'skyline';
    }
    const base = circuit.terrain?.base ?? 0x24402a;
    const r = (base >> 16) & 0xff;
    const b = base & 0xff;
    if (r > 0x95 && r > b + 0x30) {
        return 'dunes';
    }
    if (circuit.trees === 'deciduous' || circuit.trees === 'conifer' || circuit.trees === 'mixed') {
        return 'treeline';
    }

    return 'none';
}

/**
 * @param {string} type
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @param {boolean} night
 * @returns {number}
 */
function defaultColour(type, circuit, night) {
    const base = circuit.terrain?.base ?? 0x24402a;
    switch (type) {
        case 'mountains':
            return night ? 0x0b1018 : darken(base, 0.55);
        case 'treeline':
            return night ? 0x080c10 : darken(circuit.foliage ?? 0x2f5233, 0.6);
        case 'skyline':
            return night ? 0x0d1119 : 0x5c6878;
        case 'dunes':
            return night ? 0x161410 : lighten(base, 0.12);
        case 'sea':
            return night ? 0x0a1420 : 0x3a6d8c;
        default:
            return base;
    }
}

/**
 * @param {number} hex
 * @param {number} k
 * @returns {number}
 */
function darken(hex, k) {
    return new THREE.Color(hex).multiplyScalar(k).getHex();
}

/**
 * @param {number} hex
 * @param {number} k
 * @returns {number}
 */
function lighten(hex, k) {
    return new THREE.Color(hex).lerp(new THREE.Color(0xffffff), k).getHex();
}

/**
 * Същата формула като terrain.groundLevel / mesh.buildGround: под най-ниската
 * точка на трасето, спуснатия ръб на тревата и падините на релефа
 * (амплитуда × look.terrain.relief).
 *
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @param {number} relief
 * @returns {number}
 */
function groundLevelOf(track, circuit, relief) {
    let minY = Infinity;
    for (let i = 0; i < track.count; i++) {
        minY = Math.min(minY, track.ys[i]);
    }

    return (
        minY -
        GROUND.runoffWidth * GROUND.runoffDrop -
        GROUND.sink -
        (circuit.terrain?.amplitude ?? 0) * relief * GROUND.reliefShare
    );
}

// ── Рамка: заоблен правоъгълник около трасето ────────────────────────────

/**
 * @typedef {object} BoundsFrame
 * @property {number} cx
 * @property {number} cz
 * @property {number} hx  Полуширина на bbox-а на трасето
 * @property {number} hz
 */

/**
 * @param {import('./track.js').Track} track
 * @returns {BoundsFrame}
 */
function boundsFrame(track) {
    const { xs, zs, count } = track;
    let minX = Infinity;
    let maxX = -Infinity;
    let minZ = Infinity;
    let maxZ = -Infinity;
    for (let i = 0; i < count; i++) {
        minX = Math.min(minX, xs[i]);
        maxX = Math.max(maxX, xs[i]);
        minZ = Math.min(minZ, zs[i]);
        maxZ = Math.max(maxZ, zs[i]);
    }

    return { cx: (minX + maxX) / 2, cz: (minZ + maxZ) / 2, hx: (maxX - minX) / 2, hz: (maxZ - minZ) / 2 };
}

/**
 * Обиколка на bbox-а, отместен навън с `offset` (ъглите са дъги с този
 * радиус). Връща дължината и функция s(метри) → точка.
 *
 * @param {BoundsFrame} frame
 * @param {number} offset
 * @returns {{length: number, at: (s: number, out: {x: number, z: number}) => {x: number, z: number}}}
 */
function perimeter(frame, offset) {
    const { cx, cz, hx, hz } = frame;
    const arc = (Math.PI / 2) * offset;
    // Ред: долен ръб → дъга → десен ръб → дъга → горен ръб → дъга → ляв ръб → дъга.
    const segments = [2 * hx, arc, 2 * hz, arc, 2 * hx, arc, 2 * hz, arc];
    const length = segments.reduce((sum, l) => sum + l, 0);

    return {
        length,
        at(s, out) {
            let u = ((s % length) + length) % length;
            let k = 0;
            while (k < 7 && u > segments[k]) {
                u -= segments[k];
                k++;
            }
            const t = segments[k] > 0 ? u / segments[k] : 0;
            switch (k) {
                case 0:
                    out.x = cx - hx + 2 * hx * t;
                    out.z = cz - hz - offset;
                    break;
                case 1:
                    out.x = cx + hx + Math.sin((Math.PI / 2) * t) * offset;
                    out.z = cz - hz - Math.cos((Math.PI / 2) * t) * offset;
                    break;
                case 2:
                    out.x = cx + hx + offset;
                    out.z = cz - hz + 2 * hz * t;
                    break;
                case 3:
                    out.x = cx + hx + Math.cos((Math.PI / 2) * t) * offset;
                    out.z = cz + hz + Math.sin((Math.PI / 2) * t) * offset;
                    break;
                case 4:
                    out.x = cx + hx - 2 * hx * t;
                    out.z = cz + hz + offset;
                    break;
                case 5:
                    out.x = cx - hx - Math.sin((Math.PI / 2) * t) * offset;
                    out.z = cz + hz + Math.cos((Math.PI / 2) * t) * offset;
                    break;
                case 6:
                    out.x = cx - hx - offset;
                    out.z = cz + hz - 2 * hz * t;
                    break;
                default:
                    out.x = cx - hx - Math.cos((Math.PI / 2) * t) * offset;
                    out.z = cz - hz - Math.sin((Math.PI / 2) * t) * offset;
                    break;
            }

            return out;
        },
    };
}

// ── Ленти ────────────────────────────────────────────────────────────────

/**
 * Затворена лента: за всяка колона долен и горен връх; цветовете са по
 * колона (bottom/top). Индексирана, затворена през последната → първата.
 *
 * @param {Array<{x: number, z: number, h: number}>} columns  Позиция и височина над земята
 * @param {number} groundLevel
 * @param {THREE.Color} bottom
 * @param {THREE.Color} top
 * @param {number} uvScale  Метри на едно повторение на текстурата по u/v (0 = без uv)
 * @returns {THREE.BufferGeometry}
 */
function stripGeometry(columns, groundLevel, bottom, top, uvScale) {
    const n = columns.length;
    const positions = new Float32Array(n * 2 * 3);
    const colors = new Float32Array(n * 2 * 3);
    const uvs = uvScale > 0 ? new Float32Array(n * 2 * 2) : null;
    const indices = new Uint32Array(n * 6);
    let along = 0;

    for (let c = 0; c < n; c++) {
        const col = columns[c];
        const base = c * 6;
        positions[base] = col.x;
        positions[base + 1] = groundLevel - BOTTOM_DROP;
        positions[base + 2] = col.z;
        positions[base + 3] = col.x;
        positions[base + 4] = groundLevel + col.h;
        positions[base + 5] = col.z;

        colors[base] = bottom.r;
        colors[base + 1] = bottom.g;
        colors[base + 2] = bottom.b;
        colors[base + 3] = top.r;
        colors[base + 4] = top.g;
        colors[base + 5] = top.b;

        if (uvs) {
            if (c > 0) {
                along += Math.hypot(col.x - columns[c - 1].x, col.z - columns[c - 1].z);
            }
            uvs[c * 4] = along / uvScale;
            uvs[c * 4 + 1] = -BOTTOM_DROP / uvScale;
            uvs[c * 4 + 2] = along / uvScale;
            uvs[c * 4 + 3] = col.h / uvScale;
        }

        const next = (c + 1) % n;
        const a = c * 2;
        const b = next * 2;
        const t = c * 6;
        indices[t] = a;
        indices[t + 1] = b;
        indices[t + 2] = a + 1;
        indices[t + 3] = b;
        indices[t + 4] = b + 1;
        indices[t + 5] = a + 1;
    }

    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    geometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));
    if (uvs) {
        geometry.setAttribute('uv', new THREE.BufferAttribute(uvs, 2));
    }
    geometry.setIndex(new THREE.BufferAttribute(indices, 1));
    geometry.computeBoundingSphere();

    return geometry;
}

/**
 * Цветове на слой k: долу — цветът, смесен към мъглата (въздушна
 * перспектива, повече за далечните); горе — по-светъл за ридове/дюни
 * (небето се отразява по билата), по-тъмен за скайлайн (основата на
 * далечен град е в най-плътната мъгла, върховете стърчат над нея; светли
 * върхове четат като бели игли).
 *
 * @param {object} look
 * @param {number} k
 * @param {number} [topLift=0.06] Относителна промяна на яркостта на горния ръб
 * @returns {{bottom: THREE.Color, top: THREE.Color}}
 */
function layerColours(look, k, topLift = 0.06) {
    const mix = BACKDROP_DEFAULTS.fogMix[Math.min(k, 2)];
    const bottom = look.colour.clone().lerp(look.fog, mix);
    const top = bottom.clone().lerp(look.fog, Math.max(0, topLift) * 2).multiplyScalar(1 + topLift);

    return { bottom, top };
}

/**
 * @param {THREE.BufferGeometry} geometry
 * @param {boolean} fog
 * @param {string} name
 * @param {THREE.Material[]} disposables
 * @returns {THREE.Mesh}
 */
function layerMesh(geometry, fog, name, disposables) {
    const material = new THREE.MeshBasicMaterial({ vertexColors: true, side: THREE.DoubleSide, fog });
    disposables.push(geometry, material);
    const mesh = new THREE.Mesh(geometry, material);
    mesh.name = name;
    mesh.castShadow = false;
    mesh.receiveShadow = false;
    mesh.frustumCulled = true;

    return mesh;
}

/**
 * Колони по обиколката със стъпка ~step m и височина от профила.
 *
 * @param {BoundsFrame} frame
 * @param {number} offset
 * @param {number} step
 * @param {(s: number, length: number, i: number) => number} heightAt
 * @returns {Array<{x: number, z: number, h: number}>}
 */
function sampleColumns(frame, offset, step, heightAt) {
    const ring = perimeter(frame, offset);
    const n = Math.min(2048, Math.max(96, Math.round(ring.length / step)));
    const columns = [];
    const point = { x: 0, z: 0 };
    for (let i = 0; i < n; i++) {
        const s = (i / n) * ring.length;
        ring.at(s, point);
        columns.push({ x: point.x, z: point.z, h: heightAt(s, ring.length, i) });
    }

    return columns;
}

/**
 * Периодичен 1D value noise по обиколката: L клетки на цикъл, така че краят
 * се затваря без шев.
 *
 * @param {number} u  Позиция в клетки
 * @param {number} cells
 * @param {number} seed
 * @returns {number} [0, 1]
 */
function loopNoise(u, cells, seed) {
    const i0 = Math.floor(u);
    const f = u - i0;
    const t = f * f * (3 - 2 * f);
    const a = hashNoise((((i0 % cells) + cells) % cells) * 91.7 + seed);
    const b = hashNoise(((((i0 + 1) % cells) + cells) % cells) * 91.7 + seed);

    return a + (b - a) * t;
}

// ── Типове ───────────────────────────────────────────────────────────────

/**
 * Три реда ридове: ridged noise (1 − |2n − 1|) с две октави, амплитудата
 * расте навън, широка модулация оставя долини.
 */
function mountains(frame, look, disposables) {
    const meshes = [];
    const ratios = BACKDROP_DEFAULTS.layerRatios;
    const scale = [0.55, 0.8, 1.2];

    for (let k = 0; k < 3; k++) {
        const offset = look.distance * ratios[k];
        const columns = sampleColumns(frame, offset, 40, (s, length) => {
            const cells = Math.max(8, Math.round(length / 900));
            const u = (s / length) * cells;
            const ridge = 1 - Math.abs(2 * loopNoise(u, cells, look.seed + k * 17) - 1);
            const detail = 1 - Math.abs(2 * loopNoise(u * 3, cells * 3, look.seed + k * 29) - 1);
            const valley = 0.55 + 0.45 * loopNoise(u * 0.5, Math.max(4, cells / 2), look.seed + k * 41);
            return look.height * scale[k] * (ridge * 0.7 + detail * 0.3) * valley + 6;
        });
        const { bottom, top } = layerColours(look, k);
        meshes.push(layerMesh(stripGeometry(columns, look.groundLevel, bottom, top, 0), k < 2, `backdrop-ridge-${k}`, disposables));
    }

    return meshes;
}

/**
 * Гора до хоризонта: назъбена линия от корони (10-14 m ± 15 %) отпред и
 * меки хълмове с назъбен връх отзад.
 */
function treeline(frame, look, disposables) {
    const ratios = BACKDROP_DEFAULTS.layerRatios;
    const crown = (i, seed) => 12 * (0.85 + hashNoise(i * 3.3 + seed) * 0.3) * (1 + (i % 2) * 0.08);

    const near = sampleColumns(frame, look.distance * ratios[0], 7, (s, length, i) => crown(i, look.seed));
    const far = sampleColumns(frame, look.distance * ratios[2], 9, (s, length, i) => {
        const cells = Math.max(8, Math.round(length / 700));
        const u = (s / length) * cells;
        const hill = loopNoise(u, cells, look.seed + 5) * 0.7 + loopNoise(u * 2.3, cells * 2, look.seed + 9) * 0.3;
        return look.height * hill + crown(i, look.seed + 7) * 0.6;
    });

    const c0 = layerColours(look, 0, 0.03);
    const c2 = layerColours(look, 2, 0.03);

    return [
        layerMesh(stripGeometry(near, look.groundLevel, c0.bottom, c0.top, 0), true, 'backdrop-treeline-0', disposables),
        layerMesh(stripGeometry(far, look.groundLevel, c2.bottom, c2.top, 0), false, 'backdrop-treeline-1', disposables),
    ];
}

/**
 * Град: лотове по 18-40 m с плоски покриви (две колони на лот, за да са
 * вертикални ръбовете). Височината следва бавен шум по обиколката (квартали
 * с високо и ниско строителство — на 1 km еднакво случайни 10 m лотове четат
 * като гребен от игли), с тежка опашка отгоре и рядка кула (1 на ~25 лота,
 * 24-32 m широка, 100-160 m). Два слоя: нисък отпред (×0.45), пълен отзад.
 * Нощем — адитивен слой със светещи прозорци (uv в метри, 4 m етаж).
 */
function skyline(frame, look, disposables) {
    const ratios = BACKDROP_DEFAULTS.layerRatios;
    const meshes = [];
    const heightScale = look.height / BACKDROP_DEFAULTS.height.skyline;
    const layers = [
        { k: 0, offset: look.distance * ratios[0], scale: 0.45, fog: true },
        { k: 2, offset: look.distance * ratios[2], scale: 1.0, fog: false },
    ];

    for (const layer of layers) {
        const ring = perimeter(frame, layer.offset);
        const cells = Math.max(6, Math.round(ring.length / 700));
        const columns = [];
        const point = { x: 0, z: 0 };
        let s = 0;
        let lot = 0;
        while (s < ring.length) {
            const seed = look.seed + layer.k * 1000 + lot;
            const tower = lot % 25 === 9;
            const width = tower ? 24 + hashNoise(seed * 1.3) * 8 : 18 + hashNoise(seed * 1.9) * 22;
            const end = Math.min(ring.length, s + width);
            // Квартал: 0.5-1.5 × базата по бавен шум; отгоре тежка опашка.
            const district = 0.5 + loopNoise((s / ring.length) * cells, cells, look.seed + layer.k * 7);
            const roll = hashNoise(seed * 2.7);
            const raw = tower ? 100 + hashNoise(seed * 3.1) * 60 : (22 + roll * roll * 50) * district;
            const height = raw * layer.scale * heightScale;
            ring.at(s, point);
            columns.push({ x: point.x, z: point.z, h: height });
            ring.at(end - 0.01, point);
            columns.push({ x: point.x, z: point.z, h: height });
            s = end;
            lot++;
        }

        const { bottom, top } = layerColours(look, layer.k, -0.08);
        const geometry = stripGeometry(columns, look.groundLevel, bottom, top, look.night ? WINDOW_METRES * WINDOW_CELLS : 0);
        meshes.push(layerMesh(geometry, layer.fog, `backdrop-skyline-${layer.k}`, disposables));

        if (look.night) {
            const windows = makeWindowTexture(look.seed + layer.k);
            if (windows) {
                const material = new THREE.MeshBasicMaterial({
                    map: windows,
                    transparent: true,
                    blending: THREE.AdditiveBlending,
                    depthWrite: false,
                    side: THREE.DoubleSide,
                    fog: layer.fog,
                    // > 1: bloom-ът на десктопа подхваща прозорците.
                    color: new THREE.Color(1.5, 1.1, 0.6),
                });
                disposables.push(material, windows);
                const lit = new THREE.Mesh(geometry, material);
                lit.name = `backdrop-windows-${layer.k}`;
                lit.castShadow = false;
                lit.receiveShadow = false;
                lit.frustumCulled = true;
                meshes.push(lit);
            }
        }
    }

    return meshes;
}

/**
 * Прозорци: 128² с 16×16 клетки по 4 m, ~22 % светят (2×2 px в 8 px клетка,
 * ≈1.4 % покритие). Покритието е важно: на 1 km мип нивата усредняват
 * картата до равномерно сияние и при 5 % покритие целият град светеше
 * бледо. RepeatWrapping; в Node няма canvas → null (без прозорци).
 *
 * @param {number} seed
 * @returns {THREE.CanvasTexture|null}
 */
function makeWindowTexture(seed) {
    if (typeof document === 'undefined') {
        return null;
    }
    const size = 128;
    const cell = size / WINDOW_CELLS;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#000';
    ctx.fillRect(0, 0, size, size);

    for (let y = 0; y < WINDOW_CELLS; y++) {
        for (let x = 0; x < WINDOW_CELLS; x++) {
            const r = hashNoise((y * WINDOW_CELLS + x) * 7.3 + seed);
            if (r > 0.22) {
                continue;
            }
            const warm = hashNoise((y * WINDOW_CELLS + x) * 3.1 + seed) > 0.7;
            ctx.fillStyle = warm ? 'rgb(255, 214, 150)' : 'rgb(220, 232, 255)';
            ctx.fillRect(x * cell + 3, y * cell + 3, 2, 2);
        }
    }

    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.SRGBColorSpace;
    texture.wrapS = THREE.RepeatWrapping;
    texture.wrapT = THREE.RepeatWrapping;
    texture.magFilter = THREE.NearestFilter;

    return texture;
}

/**
 * Дюни: два реда нисък гладък шум с къса дължина на вълната.
 */
function dunes(frame, look, disposables) {
    const ratios = BACKDROP_DEFAULTS.layerRatios;
    const meshes = [];
    const scale = [0.7, 1.0];
    const layers = [0, 2];

    for (let j = 0; j < 2; j++) {
        const k = layers[j];
        const columns = sampleColumns(frame, look.distance * ratios[k], 30, (s, length) => {
            const cells = Math.max(12, Math.round(length / 400));
            const u = (s / length) * cells;
            const soft = loopNoise(u, cells, look.seed + k * 13) * 0.65 + loopNoise(u * 2, cells * 2, look.seed + k * 31) * 0.35;
            return look.height * scale[j] * soft * soft + 4;
        });
        const { bottom, top } = layerColours(look, k);
        meshes.push(layerMesh(stripGeometry(columns, look.groundLevel, bottom, top, 0), k < 2, `backdrop-dunes-${k}`, disposables));
    }

    return meshes;
}

/**
 * Море: плосък пръстен вода над нивото на земята от вътрешния радиус до
 * далеч отвъд мъглата (fog: false, цветът е смесен към мъглата), плюс нисък
 * бряг от ридове на външния радиус.
 */
function sea(frame, look, disposables) {
    const inner = perimeter(frame, look.distance * BACKDROP_DEFAULTS.layerRatios[0]);
    const outer = perimeter(frame, look.distance * BACKDROP_DEFAULTS.layerRatios[0] + 3500);
    const n = Math.min(512, Math.max(96, Math.round(inner.length / 60)));
    const positions = new Float32Array(n * 2 * 3);
    const colors = new Float32Array(n * 2 * 3);
    const indices = new Uint32Array(n * 6);
    const y = look.groundLevel + 0.3;
    const nearColour = look.colour.clone().lerp(look.fog, 0.45);
    const farColour = look.colour.clone().lerp(look.fog, 0.9);
    const point = { x: 0, z: 0 };

    for (let i = 0; i < n; i++) {
        const t = i / n;
        inner.at(t * inner.length, point);
        positions.set([point.x, y, point.z], i * 6);
        colors.set([nearColour.r, nearColour.g, nearColour.b], i * 6);
        outer.at(t * outer.length, point);
        positions.set([point.x, y, point.z], i * 6 + 3);
        colors.set([farColour.r, farColour.g, farColour.b], i * 6 + 3);

        const next = (i + 1) % n;
        indices.set([i * 2, i * 2 + 1, next * 2, next * 2, i * 2 + 1, next * 2 + 1], i * 6);
    }

    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    geometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));
    geometry.setIndex(new THREE.BufferAttribute(indices, 1));
    geometry.computeBoundingSphere();
    const water = layerMesh(geometry, false, 'backdrop-sea', disposables);

    // Далечен бряг: половин височина планини, само външният слой.
    const coast = sampleColumns(frame, look.distance, 40, (s, length) => {
        const cells = Math.max(8, Math.round(length / 900));
        const u = (s / length) * cells;
        const ridge = 1 - Math.abs(2 * loopNoise(u, cells, look.seed + 3) - 1);
        return look.height * ridge * (0.5 + 0.5 * loopNoise(u * 0.5, Math.max(4, cells / 2), look.seed + 11)) + 3;
    });
    const coastLook = { ...look, colour: new THREE.Color(look.night ? 0x0b1018 : 0x2f3a30) };
    const { bottom, top } = layerColours(coastLook, 2);

    return [water, layerMesh(stripGeometry(coast, look.groundLevel, bottom, top, 0), false, 'backdrop-coast', disposables)];
}

// ── Помощни ──────────────────────────────────────────────────────────────

/**
 * Детерминиран шум в [0,1) от число (същият като в mesh.js/decor.js).
 *
 * @param {number} n
 * @returns {number}
 */
function hashNoise(n) {
    const x = Math.sin(n * 12.9898) * 43758.5453;

    return x - Math.floor(x);
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

    return (hash >>> 0) % 100000;
}
