/**
 * Информационни бордове: 6 m панели от външната страна на завоите, зад
 * зоната за сигурност — рамка за ТВ кадъра без търговски послания.
 *
 * Панелите са ПЛОСКИ квадове (така изглеждат реалните бордове), не лента със
 * споделени върхове: споделен връх между два панела би размазал атласа през
 * границата им. Всички панели на пистата са в ЕДИН BatchedMesh на парчета по
 * ~120 m с per-object frustum culling — един draw call, а сенчестият pass и
 * камерата назад режат невидимите парчета.
 *
 * Атласът е процедурен canvas 4096×192 (8×2 панела по 512×96): използва
 * само описателни състезателни фрази и географски имена. Конфигурирани
 * „марки" не се визуализират, така че тук не може да попадне продуктово
 * позициониране. Имената на пистите са географски (Монца, Спа…).
 */

import * as THREE from 'three';

/** Дължина на панел, метри. */
export const PANEL_METRES = 6;

/** Панели в един chunk на BatchedMesh (20 × 6 m = 120 m). */
const PANELS_PER_CHUNK = 20;

/** Атлас: колони × редове панели, размер на панел в пиксели. */
const ATLAS_COLS = 8;
const ATLAS_ROWS = 2;
const PANEL_PX_W = 512;
const PANEL_PX_H = 96;

/** Акцентът на интерфейса и кербовете. */
const ACCENT_RED = '#d42a26';

/** Неутрални съобщения — описват карането, не продукти или компании. */
const TRACK_MESSAGES = ['ИГРА', 'АПЕКС', 'ЧИСТА ЛИНИЯ', 'ПИТ ЛЕЙН', 'СЕКТОР 1', 'СЕКТОР 2', 'СЕКТОР 3', 'БЪРЗА ОБИКОЛКА'];

/** Географските имена на пистите (кирилица) — IP-чисти. */
const CIRCUIT_NAMES_BG = {
    monza: 'МОНЦА',
    spa: 'СПА',
    silverstone: 'СИЛВЪРСТОУН',
    monaco: 'МОНАКО',
    suzuka: 'СУЗУКА',
    red_bull_ring: 'ШПИЛБЕРГ',
    zandvoort: 'ЗАНДВООРТ',
    interlagos: 'ИНТЕРЛАГОС',
    bahrain: 'САХИР',
    jeddah: 'ДЖЕДА',
    albert_park: 'МЕЛБЪРН',
    shanghai: 'ШАНХАЙ',
    miami: 'МАЯМИ',
    imola: 'ИМОЛА',
    catalunya: 'БАРСЕЛОНА',
    villeneuve: 'МОНРЕАЛ',
    hungaroring: 'МОДЬОРОД',
    baku: 'БАКУ',
    marina_bay: 'СИНГАПУР',
    americas: 'ОСТИН',
    rodriguez: 'МЕКСИКО СИТИ',
    vegas: 'ЛАС ВЕГАС',
    losail: 'ЛУСАИЛ',
    yas_marina: 'АБУ ДАБИ',
};

/** Палитри фон/текст за марковите панели (цикличен избор). */
const PANEL_PALETTES = [
    ['#141518', '#f2f2f2'],
    [ACCENT_RED, '#ffffff'],
    ['#f2f2f2', '#141518'],
    ['#14213d', '#ffffff'],
    ['#ffffff', ACCENT_RED],
    ['#1f6f50', '#f4f4f4'],
    ['#e0a83a', '#141518'],
    ['#2470b8', '#ffffff'],
];

/**
 * Строи бордовете на пистата.
 *
 * @param {import('./track.js').Track} track
 * @param {{from: number, to: number, sign: number}} pit Пит комплексът в редове спрямо ред 0
 * @param {object} options
 * @param {Array<{from: number, to: number, side: number}>} options.segments Диапазони в РЕДОВЕ (могат да са отрицателни/над count) и страна (+1 = по нормалата)
 * @param {(meters: number, offset: number) => number} options.ground Височина на земята на дадено място
 * @param {(geometries: THREE.BufferGeometry[], material: THREE.Material, flags: {castShadow: boolean}) => THREE.BatchedMesh} options.batch
 * @param {{sign: number, fromMeters: number, toMeters: number}|null} [options.grandstand] Стартовите трибуни (mesh.js държи своите бордове там)
 * @param {{rowFrom: number, rowTo: number}|null} [options.tunnel] Тунелът (стените му са на същото място)
 * @param {boolean} [options.streetWalls] Градска писта: панелите стъпват върху мантинелата
 * @param {string} [options.slug] За името на пистата и семето
 * @param {object} [options.look] circuit.look; текстовете му умишлено не се визуализират
 * @param {number} [options.maxAniso]
 * @param {boolean} [options.castShadow]
 * @returns {{mesh: THREE.BatchedMesh, material: THREE.MeshStandardMaterial, texture: THREE.CanvasTexture, panels: number}|null}
 */
export function buildHoardings(track, pit, options) {
    const { count, spacing } = track;
    const lapMetres = count * spacing;
    const slots = Math.max(1, Math.floor(lapMetres / PANEL_METRES));
    const seed = hashString(options.slug ?? track.slug ?? '') % 1000;

    const skip = makeSkipTest(track, pit, options);

    // Заетите слотове по страна — правите и завоите се припокриват, а един
    // слот трябва да носи точно един панел.
    const placed = new Map();
    for (const segment of options.segments) {
        const fromM = segment.from * spacing;
        const toM = segment.to * spacing;
        for (let m = Math.floor(fromM / PANEL_METRES) * PANEL_METRES; m + PANEL_METRES <= toM + 1e-6; m += PANEL_METRES) {
            const slot = (((Math.round(m / PANEL_METRES) % slots) + slots) % slots);
            const key = segment.side > 0 ? slot : slot + slots;
            if (placed.has(key)) {
                continue;
            }
            const row = (m + PANEL_METRES / 2) / spacing;
            if (skip(row, segment.side)) {
                continue;
            }
            placed.set(key, { slot, side: segment.side, meters: slot * PANEL_METRES });
        }
    }

    if (placed.size === 0) {
        return null;
    }

    // Последователни слотове на една страна → един run; run-овете се режат
    // на chunk-ове, всеки chunk е една геометрия (един culling обект).
    const runs = [];
    for (const side of [1, -1]) {
        const ordered = [...placed.values()].filter((p) => p.side === side).sort((a, b) => a.slot - b.slot);
        let run = null;
        for (const panel of ordered) {
            if (run && panel.slot === run[run.length - 1].slot + 1 && run.length < PANELS_PER_CHUNK) {
                run.push(panel);
            } else {
                run = [panel];
                runs.push(run);
            }
        }
    }

    const texture = makeAtlas(options);
    const material = new THREE.MeshStandardMaterial({
        map: texture,
        metalness: 0,
        roughness: 0.55,
        side: THREE.FrontSide,
    });

    const picker = makePanelPicker(seed);
    const geometries = runs.map((run) => panelRunGeometry(track, run, picker, options));
    const mesh = options.batch(geometries, material, { castShadow: options.castShadow === true });
    mesh.name = 'hoardings';
    mesh.receiveShadow = true;

    return { mesh, material, texture, panels: placed.size };
}

/**
 * Кои редове/страни НЕ получават панел: питовете от тяхната страна, стартовата
 * трибуна (mesh.js има свои бордове пред нея) и тунелът.
 *
 * @param {import('./track.js').Track} track
 * @param {{from: number, to: number, sign: number}} pit
 * @param {object} options
 * @returns {(row: number, side: number) => boolean}
 */
function makeSkipTest(track, pit, options) {
    const { count, spacing } = track;
    const grandstand = options.grandstand ?? null;
    const tunnel = options.tunnel ?? null;

    return (row, side) => {
        const i = ((Math.round(row) % count) + count) % count;
        const wrapped = i > count / 2 ? i - count : i;

        if (side === pit.sign && wrapped > pit.from - 2 && wrapped < pit.to + 2) {
            return true;
        }
        if (grandstand && side === grandstand.sign) {
            const meters = wrapped * spacing;
            if (meters > grandstand.fromMeters && meters < grandstand.toMeters) {
                return true;
            }
        }
        if (tunnel && i >= tunnel.rowFrom - 1 && i <= tunnel.rowTo + 1) {
            return true;
        }

        return false;
    };
}

/**
 * Избор на панел от атласа по слот: марките вървят на групи по 3 панела (както
 * реалните спонсорски редове), мотивите са по-редки.
 *
 * @param {number} seed
 * @returns {(slot: number, side: number) => number}
 */
function makePanelPicker(seed) {
    return (slot, side) => {
        const group = Math.floor(slot / 3);
        const roll = hashNoise(group * 1.71 + side * 13.1 + seed * 0.37);
        if (roll < 0.8) {
            // 12 информационни панела (0..11): фрази и име на мястото.
            return Math.floor(hashNoise(group * 3.3 + side * 7.7 + seed) * 12);
        }

        // Мотиви 12..14 (15 е гърбът на панелите).
        return 12 + Math.floor(hashNoise(group * 5.9 + side * 2.3 + seed) * 3);
    };
}

/**
 * Геометрията на един run панели: лице към трасето + гладък гръб (панел 15).
 * Атрибутите съвпадат с wallGeometry/stripGeometry в decor.js, за да може
 * всичко да живее в общи BatchedMesh-ове.
 *
 * @param {import('./track.js').Track} track
 * @param {Array<{slot: number, side: number, meters: number}>} run
 * @param {(slot: number, side: number) => number} pick
 * @param {object} options
 * @returns {THREE.BufferGeometry}
 */
function panelRunGeometry(track, run, pick, options) {
    const { xs, ys, zs, tx, tz, nx, nz, halfWidths, spacing, count } = track;
    const n = run.length;
    const positions = new Float32Array(n * 8 * 3);
    const normals = new Float32Array(n * 8 * 3);
    const uvs = new Float32Array(n * 8 * 2);
    const colors = new Float32Array(n * 8 * 3);
    const lateral = new Float32Array(n * 8);
    const along = new Float32Array(n * 8);
    const halfW = new Float32Array(n * 8);
    const indices = new Uint16Array(n * 12);

    const street = options.streetWalls === true;
    const padU = 6 / (ATLAS_COLS * PANEL_PX_W);
    const padV = 4 / (ATLAS_ROWS * PANEL_PX_H);

    // Позиция на m метра по обиколката при странично отместване.
    const at = (meters, side, out) => {
        const steps = meters / spacing;
        const base = Math.floor(steps);
        const frac = steps - base;
        const i = ((base % count) + count) % count;
        const j = (i + 1) % count;
        const half = halfWidths[i] + (halfWidths[j] - halfWidths[i]) * frac;
        // На градска писта панелът е върху съществуващата мантинела. На
        // постоянно трасе стои зад осемметровата зона за сигурност, точно
        // пред защитния конвейер/стекове, вместо до ръба на асфалта.
        const offset = side * (half + (street ? 1.53 : 8.15));
        out.x = xs[i] + (xs[j] - xs[i]) * frac + (nx[i] + (nx[j] - nx[i]) * frac) * offset;
        out.z = zs[i] + (zs[j] - zs[i]) * frac + (nz[i] + (nz[j] - nz[i]) * frac) * offset;
        out.y = options.ground(meters, offset) + (street ? 1.02 : -0.1);
        out.offset = offset;
        out.half = half;
        out.i = i;
    };

    const a = { x: 0, y: 0, z: 0, offset: 0, half: 0, i: 0 };
    const b = { x: 0, y: 0, z: 0, offset: 0, half: 0, i: 0 };
    const height = street ? 0.9 : 1.0;

    for (let p = 0; p < n; p++) {
        const panel = run[p];
        at(panel.meters, panel.side, a);
        at(panel.meters + PANEL_METRES, panel.side, b);

        const mid = ((Math.round((panel.meters + PANEL_METRES / 2) / spacing) % count) + count) % count;
        // Нормалата на лицето: към трасето (−side·n); гърбът — обратно.
        const fnx = -panel.side * nx[mid];
        const fnz = -panel.side * nz[mid];

        const index = pick(panel.slot, panel.side);
        const col = index % ATLAS_COLS;
        const rowA = Math.floor(index / ATLAS_COLS);
        const u0 = (col + 0) / ATLAS_COLS + padU;
        const u1 = (col + 1) / ATLAS_COLS - padU;
        const v1 = 1 - rowA / ATLAS_ROWS - padV;
        const v0 = 1 - (rowA + 1) / ATLAS_ROWS + padV;

        // Гърбът: панел 15 (плътно сиво).
        const bu0 = (ATLAS_COLS - 1) / ATLAS_COLS + padU;
        const bu1 = 1 - padU;
        const bv1 = 1 - (ATLAS_ROWS - 1) / ATLAS_ROWS - padV;
        const bv0 = padV;

        const base = p * 8;
        const corners = [
            [a.x, a.y, a.z, u0, v0, a.offset, panel.meters, a.half],
            [b.x, b.y, b.z, u1, v0, b.offset, panel.meters + PANEL_METRES, b.half],
            [b.x, b.y + height, b.z, u1, v1, b.offset, panel.meters + PANEL_METRES, b.half],
            [a.x, a.y + height, a.z, u0, v1, a.offset, panel.meters, a.half],
        ];
        for (let k = 0; k < 4; k++) {
            const c = corners[k];
            for (const [slotIndex, uu, vv, sign] of [
                [base + k, c[3], c[4], 1],
                [base + 4 + k, k === 0 || k === 3 ? bu0 : bu1, k < 2 ? bv0 : bv1, -1],
            ]) {
                positions[slotIndex * 3] = c[0];
                positions[slotIndex * 3 + 1] = c[1];
                positions[slotIndex * 3 + 2] = c[2];
                normals[slotIndex * 3] = fnx * sign;
                normals[slotIndex * 3 + 1] = 0;
                normals[slotIndex * 3 + 2] = fnz * sign;
                uvs[slotIndex * 2] = uu;
                uvs[slotIndex * 2 + 1] = vv;
                colors[slotIndex * 3] = 1;
                colors[slotIndex * 3 + 1] = 1;
                colors[slotIndex * 3 + 2] = 1;
                lateral[slotIndex] = c[5];
                along[slotIndex] = c[6];
                halfW[slotIndex] = c[7];
            }
        }

        // Навивка: (v1−v0)×(v3−v0) = t×up = +n; лицето към трасето е −side·n,
        // затова отдясно (side>0) редът се обръща. Гърбът — обратно.
        const t = p * 12;
        const flip = panel.side > 0;
        const front = flip ? [0, 2, 1, 0, 3, 2] : [0, 1, 2, 0, 2, 3];
        const back = flip ? [0, 1, 2, 0, 2, 3] : [0, 2, 1, 0, 3, 2];
        for (let k = 0; k < 6; k++) {
            indices[t + k] = base + front[k];
            indices[t + 6 + k] = base + 4 + back[k];
        }
    }

    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    geometry.setAttribute('normal', new THREE.BufferAttribute(normals, 3));
    geometry.setAttribute('uv', new THREE.BufferAttribute(uvs, 2));
    geometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));
    geometry.setAttribute('aLateral', new THREE.BufferAttribute(lateral, 1));
    geometry.setAttribute('aAlong', new THREE.BufferAttribute(along, 1));
    geometry.setAttribute('aHalfWidth', new THREE.BufferAttribute(halfW, 1));
    geometry.setIndex(new THREE.BufferAttribute(indices, 1));
    geometry.computeBoundingSphere();

    return geometry;
}

// ── Атласът ──────────────────────────────────────────────────────────────

/**
 * 16-те панела на атласа (8×2). Индекси 0..11 марки, 12..14 мотиви, 15 гръб.
 *
 * @param {object} options
 * @returns {THREE.CanvasTexture}
 */
function makeAtlas(options) {
    const w = ATLAS_COLS * PANEL_PX_W;
    const h = ATLAS_ROWS * PANEL_PX_H;
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');

    const specs = panelSpecs(options);
    for (let index = 0; index < ATLAS_COLS * ATLAS_ROWS; index++) {
        const x = (index % ATLAS_COLS) * PANEL_PX_W;
        const y = Math.floor(index / ATLAS_COLS) * PANEL_PX_H;
        drawPanel(ctx, x, y, specs[index] ?? specs[specs.length - 1]);
    }

    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.SRGBColorSpace;
    texture.wrapS = THREE.ClampToEdgeWrapping;
    texture.wrapT = THREE.ClampToEdgeWrapping;
    texture.anisotropy = Math.max(1, options.maxAniso ?? 1);

    return texture;
}

/**
 * Описанията на 16-те панела: неутрални състезателни съобщения,
 * географското име на пистата, мотиви и гръб.
 *
 * @param {object} options
 * @returns {Array<object>}
 */
function panelSpecs(options) {
    const name = CIRCUIT_NAMES_BG[options.slug] ?? 'ИГРА';

    const specs = [
        { kind: 'text', text: 'ИГРА', bg: '#141518', fg: '#f2f2f2' },
        { kind: 'text', text: 'АПЕКС', bg: ACCENT_RED, fg: '#ffffff' },
        { kind: 'text', text: 'ЧИСТА ЛИНИЯ', bg: '#f2f2f2', fg: '#141518' },
        { kind: 'text', text: name, bg: '#14213d', fg: '#ffffff' },
    ];
    for (let k = 0; k < 8; k++) {
        const [bg, fg] = PANEL_PALETTES[(k + 3) % PANEL_PALETTES.length];
        specs.push({ kind: 'text', text: TRACK_MESSAGES[k % TRACK_MESSAGES.length], bg, fg });
    }
    specs.push({ kind: 'stripes', bg: '#f2f2f2', fg: ACCENT_RED });
    specs.push({ kind: 'chevrons', bg: '#141518', fg: '#f2f2f2' });
    specs.push({ kind: 'checker', bg: '#f2f2f2', fg: '#141518' });
    specs.push({ kind: 'plain', bg: '#5a5d63' });

    return specs;
}

/**
 * Рисува един панел 512×96 на дадено място в атласа.
 *
 * @param {CanvasRenderingContext2D} ctx
 * @param {number} x
 * @param {number} y
 * @param {object} spec
 */
function drawPanel(ctx, x, y, spec) {
    const w = PANEL_PX_W;
    const h = PANEL_PX_H;
    ctx.fillStyle = spec.bg;
    ctx.fillRect(x, y, w, h);

    if (spec.kind === 'plain') {
        return;
    }

    if (spec.kind === 'stripes') {
        ctx.fillStyle = spec.fg;
        for (let s = -h; s < w + h; s += 64) {
            ctx.beginPath();
            ctx.moveTo(x + s, y + h);
            ctx.lineTo(x + s + 32, y + h);
            ctx.lineTo(x + s + 32 + h, y);
            ctx.lineTo(x + s + h, y);
            ctx.closePath();
            ctx.fill();
        }
        return;
    }

    if (spec.kind === 'chevrons') {
        ctx.strokeStyle = spec.fg;
        ctx.lineWidth = 10;
        for (let s = 0; s < w + h; s += 56) {
            ctx.beginPath();
            ctx.moveTo(x + s - h / 2, y + 8);
            ctx.lineTo(x + s, y + h / 2);
            ctx.lineTo(x + s - h / 2, y + h - 8);
            ctx.stroke();
        }
        return;
    }

    if (spec.kind === 'checker') {
        ctx.fillStyle = spec.fg;
        const cell = h / 2;
        for (let cx = 0; cx < w / cell; cx++) {
            for (let cy = 0; cy < 2; cy++) {
                if ((cx + cy) % 2 === 0) {
                    ctx.fillRect(x + cx * cell, y + cy * cell, cell, cell);
                }
            }
        }
        return;
    }

    // Текст: побира се в 86 % от ширината, максимум 64 px височина.
    ctx.fillStyle = spec.fg;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    let fontSize = 64;
    ctx.font = `bold ${fontSize}px sans-serif`;
    const measured = ctx.measureText(spec.text).width || 1;
    const maxWidth = w * (spec.dot ? 0.78 : 0.86);
    if (measured > maxWidth) {
        fontSize = Math.max(28, Math.floor((fontSize * maxWidth) / measured));
        ctx.font = `bold ${fontSize}px sans-serif`;
    }
    const textWidth = ctx.measureText(spec.text).width || 1;
    const cx = x + w / 2 - (spec.dot ? 10 : 0);
    ctx.fillText(spec.text, cx, y + h / 2 + 2);

    if (spec.dot) {
        // Червената точка от логото, след името.
        ctx.fillStyle = ACCENT_RED;
        ctx.beginPath();
        ctx.arc(cx + textWidth / 2 + 18, y + h / 2 + fontSize * 0.28, fontSize * 0.14, 0, Math.PI * 2);
        ctx.fill();
    }
}

// ── Дребни помощници ─────────────────────────────────────────────────────

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

/**
 * FNV-1a хеш на низ — семе от slug-а, за да е разпределението на марките
 * различно на всяка писта, но еднакво при всяко зареждане.
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
