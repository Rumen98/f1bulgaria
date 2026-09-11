/**
 * Теренът до хоризонта: една непрекъсната, леко вълнообразна, ТЕКСТУРИРАНА
 * повърхност, която тръгва от банкета и стига до мъглата без ръб и без
 * цветова стъпка — първият стълб на „Slow Roads" усещането.
 *
 * Три неща живеят тук и никъде другаде:
 *
 *   1. Семплерът (createTerrainSampler): височинна мрежа, ОБЩА за терена,
 *      дърветата, сградите и ориентирите. Всеки, който „стъпва на земята",
 *      чете нея — иначе обектите четат непрекъснатата функция, а мрежата само
 *      възлите ѝ, и дърво между два възела виси или потъва с метри.
 *   2. Теренният меш (buildTerrain): вътрешна равномерна мрежа върху възлите
 *      на семплера + външни „пръстени" с растящи клетки до ~3 km отвъд
 *      трасето. Пръстените заменят старата хоризонтна плоскост: тя стоеше на
 *      едно ниво под целия терен и на хълмисти писти (Спа, Шпилберг) теренът
 *      завършваше с десетки метри скала над нея, а цветът ѝ (base×0.72)
 *      правеше тъмен пръстен на ~700 m. Сега външният ръб слиза плавно към
 *      groundLevel на дължина 2.4 km (под 5° и на Спа) — един draw call, без
 *      шев и без втори материал.
 *   3. Континуитетът с банкета: метрични UV (TILE_METRES) и същите PBR карти
 *      като вержа, а вертекс цветовете се смесват към тона на вержа в първите
 *      ~25 m от асфалта. Далече от трасето същите вертекс цветове носят
 *      макро-вариацията на палитрата (base/accent) като ТОН върху текстурата,
 *      а не като абсолютен цвят — иначе текстурата × тъмен цвят даваше кал.
 *
 * Семплерът НИКОГА не се чете от симулацията (sim.js смята повърхността от
 * track.ys) — всичко тук е презентация и не влияе на времената.
 */

import * as THREE from 'three';

// Синхронизирани с mesh.js — теренът и банкетът трябва да се снаждат.
const RUNOFF_DROP = 0.035;
const RUNOFF_WIDTH = 8;
const Y_GRASS = -0.12;

/**
 * Метри на едно повторение на текстурата: u = x / TILE_METRES. Същият мащаб
 * като метричните UV на вержа (aLateral / 4), така че при споделени карти с
 * repeat (1, 1) шарката на банкета и на терена е с еднаква големина.
 */
export const TILE_METRES = 4;

/** Колко навън от трасето (от bounding box-а му) стига вътрешната мрежа. */
const MARGIN = 700;

/**
 * Външните пръстени: отстояние на всеки ред върхове отвъд MARGIN. Клетките
 * растат геометрично — далечните са в пълна мъгла и не носят детайл, а
 * последният пръстен (2.8 km отвъд полето, ≥ 3.5 km от всяка точка на
 * трасето) е отвъд camera.far (2200 m) откъдето и да гледаш.
 */
const OUTER_RINGS = [45, 130, 300, 600, 1000, 1500, 2100, 2800];

/** Дължина на спускането от ръба на полето към groundLevel, метри. */
const OUTER_FADE = 2400;

/**
 * Целева клетка на вътрешната мрежа, метри. Сама по себе си резолюцията не
 * е еднаква за всички писти: Джеда е 2.8 km дълга и 0.6 km широка — при
 * фиксиран брой клетки на ос тя би получила 30 m × 14 m правоъгълници.
 */
/**
 * Construction-time целева клетка на вътрешната мрежа. Auto/High пазят
 * досегашните 20 m; Ultra 18 m е ~23% повече клетки/върхове по площ, докато
 * Low/Medium действително строят по-рядък меш. RES_MIN/RES_MAX продължават да
 * ограничават необичайно тесни или дълги писти. lowPower винаги остава на
 * старите 26 m независимо от ръчния quality избор.
 *
 * | profile   | cell target |
 * |-----------|------------:|
 * | low       |        30 m |
 * | medium    |        24 m |
 * | auto/high |        20 m |
 * | ultra     |        18 m |
 * | lowPower  |        26 m |
 */
const CELL_TARGET = Object.freeze({ low: 30, medium: 24, high: 20, ultra: 18, lowPower: 26 });
const RES_MIN = 60;
const RES_MAX = 200;

/**
 * @param {{lowPower?: boolean, quality?: object}} options
 * @returns {'low'|'medium'|'high'|'ultra'|'lowPower'}
 */
function terrainQualityProfile(options) {
    if (options.lowPower === true) {
        return 'lowPower';
    }
    if (options.quality?.csmQuality === 'ultra' || options.quality?.ao === true) {
        return 'ultra';
    }
    if (options.quality?.csmQuality === 'low') {
        return 'low';
    }
    if (options.quality?.csmQuality === 'medium') {
        return 'medium';
    }

    return 'high';
}

/**
 * Колко под нивото на банкета стои базата на терена. Клетките са ~20 m, а
 * вержът следва профила точно — билинейната грешка по гребени и в завои е
 * десетина сантиметра, а днешният верж (спад от осевата, не от ръба) е с
 * половин ширина × 0.035 по-ниско от метричния. 0.45 покрива и двете, без
 * теренът да пробива, и без видима стъпка на 8 m от асфалта.
 */
const TERRAIN_SINK = 0.45;

/** Пясъчният тон на пустинните писти (верж И терен), sRGB. */
export const SAND_TINT = 0xf0e2c0;

/**
 * Настройки на релефа по вид терен — това, което circuit.look.terrain
 * презаписва поле по поле. `scale` умножава дължините на вълните на шума
 * (340/110/34 m), `ridged` прави първата октава с остри гребени
 * (1 − |2n − 1|: алпийски ридове, дюни), `relief` умножава амплитудата на
 * пистата, `stretch` разтяга шума по X (дюни, успоредни на брега).
 *
 * @type {Readonly<Record<'grass'|'sand'|'mixed', {scale: number, ridged: boolean, relief: number, stretch: number}>>}
 */
export const TERRAIN_DEFAULTS = Object.freeze({
    grass: Object.freeze({ scale: 1, ridged: false, relief: 1, stretch: 1 }),
    // Пустинята е почти равна: рядка ниска дюна, дълги вълни.
    sand: Object.freeze({ scale: 1.4, ridged: false, relief: 0.6, stretch: 1 }),
    // Пясък с трева (Зандвоорт): къси остри дюни.
    mixed: Object.freeze({ scale: 0.6, ridged: true, relief: 1, stretch: 1.4 }),
});

/**
 * Резервни стойности по slug за времето, докато atmosphere не е авторирал
 * circuit.look.terrain за всички писти — четат се само при липсващо поле.
 *
 * @type {Readonly<Record<string, Partial<{kind: 'grass'|'sand'|'mixed', scale: number, ridged: boolean, relief: number, stretch: number}>>>}
 */
const FALLBACK_BY_SLUG = Object.freeze({
    bahrain: { kind: 'sand' },
    jeddah: { kind: 'sand' },
    losail: { kind: 'sand' },
    yas_marina: { kind: 'sand' },
    zandvoort: { kind: 'mixed', scale: 0.45, stretch: 1.8 },
    // Ардените и Щирия: дълги ридове, не търкалящи се хълмчета.
    spa: { scale: 1.6, ridged: true },
    red_bull_ring: { scale: 1.5, ridged: true },
});

/**
 * @typedef {object} TerrainLook
 * @property {'grass'|'sand'|'mixed'} kind
 * @property {number} scale
 * @property {boolean} ridged
 * @property {number} relief
 * @property {number} stretch
 */

/**
 * Разрешава вида и релефа на терена: circuit.look.terrain → резерв по slug →
 * TERRAIN_DEFAULTS за вида. Всеки консуматор чете look през това, за да
 * получи едни и същи стойности (текстурен сет, тон, релеф).
 *
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @returns {TerrainLook}
 */
export function resolveTerrainLook(track, circuit) {
    const authored = circuit.look?.terrain ?? {};
    const fallback = FALLBACK_BY_SLUG[track.slug] ?? {};
    const kind = authored.kind ?? fallback.kind ?? 'grass';
    const defaults = TERRAIN_DEFAULTS[kind] ?? TERRAIN_DEFAULTS.grass;

    return {
        kind: TERRAIN_DEFAULTS[kind] ? kind : 'grass',
        scale: positive(authored.scale ?? fallback.scale ?? defaults.scale, defaults.scale),
        ridged: Boolean(authored.ridged ?? fallback.ridged ?? defaults.ridged),
        relief: nonNegative(authored.relief ?? fallback.relief ?? defaults.relief, defaults.relief),
        stretch: positive(authored.stretch ?? fallback.stretch ?? defaults.stretch, defaults.stretch),
    };
}

/**
 * Нивото на далечното поле — същата формула като старата хоризонтна плоскост
 * (mesh.js buildGround): под най-ниската точка на трасето, спуснатия ръб на
 * банкета и най-ниските падини на релефа. Пази се като обща референция за
 * фона (backdrop) и подпорите извън мрежата.
 *
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @returns {number}
 */
export function groundLevel(track, circuit) {
    const { ys, count } = track;
    let minY = Infinity;
    for (let i = 0; i < count; i++) {
        if (ys[i] < minY) {
            minY = ys[i];
        }
    }

    const amplitude = (circuit.terrain?.amplitude ?? 8) * resolveTerrainLook(track, circuit).relief;

    return minY - RUNOFF_WIDTH * RUNOFF_DROP - 1.4 - amplitude * 0.35;
}

/**
 * Височината на земята под подпора на ред i със странично отместване offset
 * (по нормалата): в обхвата на банкета — точната формула на вержа, отвъд него
 * — семплерът. Една функция за купчините гуми, стълбовете, маршалските
 * постове и табелите, за да не „висят" на хълмиста писта.
 *
 * @param {import('./track.js').Track} track
 * @param {TerrainSampler} sampler
 * @param {number} i Индекс на реда (wrap по модул)
 * @param {number} offset Метри по нормалата, със знак
 * @returns {number}
 */
export function groundY(track, sampler, i, offset) {
    const { xs, ys, zs, nx, nz, halfWidths, bankSlope, count } = track;
    const k = ((i % count) + count) % count;
    const half = halfWidths[k];
    const abs = Math.abs(offset);

    if (abs <= half + RUNOFF_WIDTH) {
        return ys[k] + Y_GRASS - Math.max(0, abs - half) * RUNOFF_DROP - offset * bankSlope[k];
    }

    return sampler.heightAt(xs[k] + nx[k] * offset, zs[k] + nz[k] * offset);
}

/**
 * @typedef {object} TerrainSampler
 * @property {(x: number, z: number) => number} heightAt   Билинейна височина (вкл. външното спускане)
 * @property {(x: number, z: number) => number} height     Псевдоним на heightAt
 * @property {(x: number, z: number) => number} trackDistAt Разстояние до осевата линия, метри (билинейно)
 * @property {(x: number, z: number) => number} edgeDistAt  Разстояние до ръба на асфалта (dist − полуширина), метри
 * @property {number} centerX
 * @property {number} centerZ
 * @property {number} sizeX   Ширина на вътрешната мрежа (bbox + 2·margin)
 * @property {number} sizeZ
 * @property {number} resX    Брой клетки по X
 * @property {number} resZ
 * @property {'low'|'medium'|'high'|'ultra'|'lowPower'} qualityProfile Construction-time LOD
 * @property {number} margin  Метри от bbox-а на трасето до ръба на вътрешната мрежа
 * @property {number} groundLevel Нивото на далечното поле
 * @property {THREE.Color} meanColor Средният процедурен цвят на терена (linear)
 * @property {TerrainLook} look
 * @property {{minX: number, maxX: number, minZ: number, maxZ: number}} bounds Bbox на трасето
 */

/**
 * Прекомпютва мрежата на терена веднъж. Вътре в полето — билинейно по
 * възлите; извън него — ръбовата височина, спусната към groundLevel с
 * разстоянието (същата функция строи и външните пръстени на меша, така че
 * фонът и подпорите отвъд полето стъпват точно на повърхността).
 *
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @param {{lowPower?: boolean, quality?: object}} [options]
 * @returns {TerrainSampler}
 */
export function createTerrainSampler(track, circuit, options = {}) {
    const { xs, zs, count } = track;
    const look = resolveTerrainLook(track, circuit);
    const qualityProfile = terrainQualityProfile(options);

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

    const margin = MARGIN;
    const sizeX = maxX - minX + margin * 2;
    const sizeZ = maxZ - minZ + margin * 2;
    const centerX = (minX + maxX) / 2;
    const centerZ = (minZ + maxZ) / 2;
    const cell = CELL_TARGET[qualityProfile];
    const resX = clampInt(Math.round(sizeX / cell), RES_MIN, RES_MAX);
    const resZ = clampInt(Math.round(sizeZ / cell), RES_MIN, RES_MAX);
    const nX = resX + 1;
    const nZ = resZ + 1;
    const x0 = centerX - sizeX / 2;
    const z0 = centerZ - sizeZ / 2;
    const x1 = x0 + sizeX;
    const z1 = z0 + sizeZ;
    const stepX = sizeX / resX;
    const stepZ = sizeZ / resZ;
    const halfExtentX = (maxX - minX) / 2;
    const halfExtentZ = (maxZ - minZ) / 2;
    const level = groundLevel(track, circuit);
    const amplitude = (circuit.terrain?.amplitude ?? 8) * look.relief;
    const rampNear = circuit.terrain?.rampNear ?? 60;
    const rampFar = circuit.terrain?.rampFar ?? 220;

    // Потискане на релефа под водата на пристанището (Монако) — в мрежата,
    // за да важи еднакво за терена И за обектите върху него.
    const harbor = circuit.landmark?.type === 'harbor' ? harborFrame(track, circuit.landmark) : null;

    const heights = new Float32Array(nX * nZ);
    const dists = new Float32Array(nX * nZ);
    const edges = new Float32Array(nX * nZ);
    const nearest = { dist: 0, half: 0, base: 0 };
    const reachBuffer = new Int32Array((count >> 1) + 1);
    const base = new THREE.Color(circuit.terrain?.base ?? 0x24402a);
    const accent = new THREE.Color(circuit.terrain?.accent ?? 0x315233);
    const mean = new THREE.Color(0, 0, 0);
    const mixed = new THREE.Color();

    for (let iz = 0; iz < nZ; iz++) {
        for (let ix = 0; ix < nX; ix++) {
            const x = x0 + ix * stepX;
            const z = z0 + iz * stepZ;
            trackBaseAt(track, x, z, nearest, reachBuffer);

            // Релефът гасне към ръба на полето, за да няма какво да „пада" в
            // спускането отвъд него (ръбът е равен на нивото на банкета).
            const edgeDist = Math.max(0, Math.abs(x - centerX) - halfExtentX, Math.abs(z - centerZ) - halfExtentZ);
            const edgeFade = 1 - smoothstep(margin - 260, margin - 60, edgeDist);
            const ramp = smoothstep(rampNear, rampFar, nearest.dist);
            const relief = (reliefNoise(x, z, look) - 0.3) * amplitude * ramp * edgeFade;

            let h = nearest.base + relief;

            if (harbor) {
                const local = harbor.toLocal(x, z);
                const outside = Math.max(Math.abs(local.u) - harbor.halfW, Math.abs(local.v) - harbor.halfD);
                if (outside < 40) {
                    // Плавно снишаване към дъното на залива.
                    const sink = 1 - clamp01(outside / 40);
                    h = Math.min(h, h * (1 - sink) + (harbor.waterY - 2.5) * sink);
                }
            }

            const node = iz * nX + ix;
            heights[node] = h;
            dists[node] = nearest.dist;
            edges[node] = nearest.dist - nearest.half;

            mixed.copy(base).lerp(accent, macroTone(x, z));
            mean.add(mixed);
        }
    }
    mean.multiplyScalar(1 / (nX * nZ));

    const bilinear = (grid, x, z) => {
        const u = Math.min(resX - 1e-6, Math.max(0, (x - x0) / stepX));
        const v = Math.min(resZ - 1e-6, Math.max(0, (z - z0) / stepZ));
        const ix = Math.floor(u);
        const iz = Math.floor(v);
        const fx = u - ix;
        const fz = v - iz;

        const h00 = grid[iz * nX + ix];
        const h10 = grid[iz * nX + ix + 1];
        const h01 = grid[(iz + 1) * nX + ix];
        const h11 = grid[(iz + 1) * nX + ix + 1];

        const a = h00 + (h10 - h00) * fx;
        const b = h01 + (h11 - h01) * fx;

        return a + (b - a) * fz;
    };

    const heightAt = (x, z) => {
        const inner = bilinear(heights, x, z);
        const outside = Math.max(0, x0 - x, x - x1, z0 - z, z - z1);
        if (outside === 0) {
            return inner;
        }

        // Отвъд полето: ръбовата височина се спуска към нивото на далечното
        // поле по дълга S-крива — под 5° и при 100 m денивелация (Спа).
        return inner + (level - inner) * smoothstep(0, OUTER_FADE, outside);
    };

    return {
        sizeX,
        sizeZ,
        centerX,
        centerZ,
        resX,
        resZ,
        qualityProfile,
        margin,
        groundLevel: level,
        meanColor: mean,
        look,
        bounds: { minX, maxX, minZ, maxZ },
        heightAt,
        height: heightAt,
        trackDistAt: (x, z) => bilinear(dists, x, z),
        edgeDistAt: (x, z) => bilinear(edges, x, z),
    };
}

/**
 * @typedef {object} TerrainLayer
 * @property {THREE.Group} group
 * @property {THREE.Mesh} mesh
 * @property {{terrain: THREE.Material}} materials  Материалът за applySurfaceShaders / текстуриране
 * @property {'grass'|'sand'|'mixed'} kind  'sand' → подай сета на чакъла, иначе на тревата
 * @property {THREE.Color} tint  Тонът на вержа, с който теренът се снажда
 * @property {boolean} textured
 * @property {(textures: {map?: THREE.Texture|null, normalMap?: THREE.Texture|null, roughnessMap?: THREE.Texture|null}) => boolean} setTextures
 * @property {(dt: number, camera: THREE.Camera) => void} update
 * @property {() => void} dispose
 */

/**
 * Строи теренния меш върху възлите на семплера + външните пръстени.
 *
 * Вертекс цветовете идват в два комплекта: процедурен (абсолютната палитра
 * base/accent — вижда се, докато няма текстура или ако тя не дойде навреме)
 * и тонален (тонът на вержа близо до асфалта, макро-вариация на палитрата
 * далече — множител върху текстурата). setTextures превключва към втория в
 * същия буфер (без нов GL буфер, без теч).
 *
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @param {TerrainSampler} sampler
 * @param {{
 *   lowPower?: boolean,
 *   quality?: object,
 *   materials?: {terrain?: THREE.Material},
 *   textures?: {map?: THREE.Texture|null, normalMap?: THREE.Texture|null, roughnessMap?: THREE.Texture|null},
 *   tint?: number|THREE.Color,
 * }} [options]
 * @returns {TerrainLayer}
 */
export function buildTerrain(track, circuit, sampler, options = {}) {
    const lowPower = options.lowPower === true;
    const look = sampler.look ?? resolveTerrainLook(track, circuit);
    const kind = look.kind;
    const tint = new THREE.Color(options.tint ?? (kind === 'sand' ? SAND_TINT : (circuit.grassTint ?? 0xffffff)));

    // Координатни оси: вътрешните възли на семплера + пръстените навън.
    const x0 = sampler.centerX - sampler.sizeX / 2;
    const z0 = sampler.centerZ - sampler.sizeZ / 2;
    const coordsX = axisCoords(x0, sampler.sizeX, sampler.resX);
    const coordsZ = axisCoords(z0, sampler.sizeZ, sampler.resZ);
    const nX = coordsX.length;
    const nZ = coordsZ.length;
    const vertexCount = nX * nZ;

    const positions = new Float32Array(vertexCount * 3);
    const uvs = new Float32Array(vertexCount * 2);
    const trackDist = new Float32Array(vertexCount);
    const procedural = new Float32Array(vertexCount * 3);
    const tonal = new Float32Array(vertexCount * 3);

    const base = new THREE.Color(circuit.terrain?.base ?? 0x24402a);
    const accent = new THREE.Color(circuit.terrain?.accent ?? 0x315233);
    const mixed = new THREE.Color();
    // Далечният тон: палитрата като ОТНОШЕНИЕ accent/base върху тона на
    // вержа — пази яркостта на текстурата, носи само характера на палитрата.
    const ratio = new THREE.Color(
        safeRatio(accent.r, base.r),
        safeRatio(accent.g, base.g),
        safeRatio(accent.b, base.b)
    );
    // При смесен терен (дюни с трева) вариацията е по-груба — петна пясък.
    const ratioWeight = kind === 'mixed' ? 1 : 0.6;

    let v = 0;
    for (let iz = 0; iz < nZ; iz++) {
        for (let ix = 0; ix < nX; ix++, v++) {
            const x = coordsX[ix];
            const z = coordsZ[iz];
            const p = v * 3;

            positions[p] = x;
            positions[p + 1] = sampler.heightAt(x, z);
            positions[p + 2] = z;

            uvs[v * 2] = x / TILE_METRES;
            uvs[v * 2 + 1] = z / TILE_METRES;
            // Разстояние до осевата линия за шейдъра на настилките (гасене на
            // детайла/мрамора с отдалечаване от трасето).
            trackDist[v] = sampler.trackDistAt(x, z);

            const tone = macroTone(x, z);
            const shade = 0.92 + hashNoise(v) * 0.16;
            mixed.copy(base).lerp(accent, tone);
            procedural[p] = clamp01(mixed.r * shade);
            procedural[p + 1] = clamp01(mixed.g * shade);
            procedural[p + 2] = clamp01(mixed.b * shade);

            // В първите метри от асфалта — точно тонът на вержа (без шейд,
            // вержът няма вертекс-вариация след текстурирането); навън
            // макро-вариацията влиза плавно.
            const far = smoothstep(6, 30, sampler.edgeDistAt(x, z));
            const rr = 1 + (ratio.r - 1) * tone * ratioWeight;
            const rg = 1 + (ratio.g - 1) * tone * ratioWeight;
            const rb = 1 + (ratio.b - 1) * tone * ratioWeight;
            const s = 1 + (shade - 1) * far;
            tonal[p] = clamp01(tint.r * (1 + (rr - 1) * far) * s);
            tonal[p + 1] = clamp01(tint.g * (1 + (rg - 1) * far) * s);
            tonal[p + 2] = clamp01(tint.b * (1 + (rb - 1) * far) * s);
        }
    }

    const cellsX = nX - 1;
    const cellsZ = nZ - 1;
    const indices = new Uint32Array(cellsX * cellsZ * 6);
    let t = 0;
    for (let iz = 0; iz < cellsZ; iz++) {
        for (let ix = 0; ix < cellsX; ix++) {
            const a = iz * nX + ix;
            const b = a + 1;
            const c = a + nX;
            const d = c + 1;
            // Обход обратно на часовника, гледано отгоре (+Y нагоре, +Z към
            // зрителя): нормалите сочат нагоре.
            indices[t++] = a;
            indices[t++] = c;
            indices[t++] = b;
            indices[t++] = b;
            indices[t++] = c;
            indices[t++] = d;
        }
    }

    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    geometry.setAttribute('uv', new THREE.BufferAttribute(uvs, 2));
    geometry.setAttribute('aTrackDist', new THREE.BufferAttribute(trackDist, 1));
    const colorAttribute = new THREE.BufferAttribute(procedural, 3);
    geometry.setAttribute('color', colorAttribute);
    geometry.setIndex(new THREE.BufferAttribute(indices, 1));
    geometry.computeVertexNormals();
    geometry.computeBoundingSphere();

    // Телефон: Lambert — матова далечна повърхност без специкуларен лоб,
    // около половината фрагментна цена на Standard върху най-големия меш в
    // сцената. dithering маха 8-битовите ивици на замъгления далечен терен.
    const ownsMaterial = !options.materials?.terrain;
    const material =
        options.materials?.terrain ??
        (lowPower
            ? new THREE.MeshLambertMaterial({ vertexColors: true, dithering: true })
            : new THREE.MeshStandardMaterial({ vertexColors: true, metalness: 0, roughness: 1, dithering: true }));
    material.name = material.name || 'terrain';

    const mesh = new THREE.Mesh(geometry, material);
    mesh.name = 'terrain';
    // Мешът е ~6 km и винаги пресича фрустума — тестът е излишен; сянка не
    // хвърля (не се вижда, а струва цял pass), но приема тази на болида.
    mesh.frustumCulled = false;
    mesh.castShadow = false;
    mesh.receiveShadow = true;
    mesh.userData.surface = kind === 'sand' ? 'sand' : 'terrain';

    const group = new THREE.Group();
    group.name = 'terrain-layer';
    group.add(mesh);

    const layer = {
        group,
        mesh,
        materials: { terrain: material },
        kind,
        tint,
        textured: false,

        setTextures(textures = {}) {
            if (!textures.map) {
                return false;
            }
            material.map = textures.map;
            material.normalMap = textures.normalMap ?? null;
            material.roughnessMap = textures.roughnessMap ?? null;
            // Споделен материал (напр. този на вержа) пази своя тон и режим
            // на цветовете — тях ги управлява собственикът му.
            if (ownsMaterial) {
                material.vertexColors = true;
                material.color.set(0xffffff);
            }
            material.needsUpdate = true;

            if (!layer.textured) {
                colorAttribute.array.set(tonal);
                colorAttribute.needsUpdate = true;
                layer.textured = true;
            }

            return true;
        },

        // Теренът е статичен; методът е част от общия договор на слоевете
        // (buildX → {group, update, dispose}), за да ги обхожда интеграторът
        // еднакво.
        update() {},

        // Картите не са наши: делят се с вержа и ги освобождава пътят на
        // Game.dispose (DISPOSABLE_MAPS по всеки материал в сцената).
        dispose() {
            geometry.dispose();
            if (ownsMaterial) {
                material.dispose();
            }
        },
    };

    if (options.textures) {
        layer.setTextures(options.textures);
    }

    return layer;
}

// ── Геометрични помощници ────────────────────────────────────────────────

/**
 * Координатите на една ос: външните пръстени (обърнати), вътрешните възли на
 * семплера, външните пръстени.
 *
 * @param {number} start Начало на вътрешната мрежа
 * @param {number} size Дължина на вътрешната мрежа
 * @param {number} res Брой вътрешни клетки
 * @returns {Float64Array}
 */
function axisCoords(start, size, res) {
    const rings = OUTER_RINGS.length;
    const coords = new Float64Array(res + 1 + rings * 2);
    const step = size / res;

    for (let r = 0; r < rings; r++) {
        coords[r] = start - OUTER_RINGS[rings - 1 - r];
        coords[rings + res + 1 + r] = start + size + OUTER_RINGS[r];
    }
    for (let i = 0; i <= res; i++) {
        coords[rings + i] = start + i * step;
    }

    return coords;
}

/** Прозорец по трасето, в който две точки се броят за ЕДНА секция (индекси). */
const SAME_SECTION = 40;

/** Хоризонтален радиус, в който друга секция дърпа терена под себе си, метри. */
const SECTION_REACH = 60;

/**
 * ±редове около най-близката точка, в които вдлъбнатина на профила сваля
 * базата (≈ една клетка на мрежата при 4 m стъпка).
 */
const VALLEY_WINDOW = 5;

/**
 * „Сгъвка": ред от същата секция се брои за съсед-конкурент само ако е
 * много по-близо по права, отколкото по трасето (d < FOLD_RATIO · along).
 * На права d ≈ along и нищо не минава; в остър завой или фиба двете рамена
 * са на 30-40 m по права и на 50-160 m по трасето. Без този филтър всяка
 * точка 36 m надолу по 3 % наклон би дърпала терена под собствения си път.
 */
const FOLD_RATIO = 0.75;

/**
 * Множител на наклона на банкинга отвъд ръба на банкета, от ниската страна
 * (петата на насипа е стръмна и гасне до 50 m). При 3 билинейната хорда на
 * 20 m мрежа остава под вътрешния ръб на 18° завой; на 26 m (телефон)
 * остават единични редове до ~1 m — виж ограниченията.
 */
const BANK_TAIL = 3;

/**
 * Базата на терена в точка (без релефа): нивото на банкета на най-близката
 * секция на трасето, спуснато навън от ръба на асфалта, плюс наклона на
 * банкинга — и НЕ по-високо от същото за която и да е друга секция наблизо.
 *
 * Защо минимум по секции: там, където две части на трасето са една до/над
 * друга (осмицата на Сузука, старият град на Баку, фибите на Интерлагос,
 * терасите на Монако), възел, чиято най-близка точка е горната секция,
 * влачеше терена ПРЕЗ асфалта на долната с метри. Долната печели: горната
 * стои на тераса/мост, което е и реалността.
 *
 * Защо прозорец по трасето: с клетки ~20 m мрежата не може да „влезе" в
 * остра падина на профила (дъното на О Руж). Възлите край нея слизат с
 * отклонението на профила под хордата в ±VALLEY_WINDOW реда, за да не
 * стърчи билинейният терен над платното.
 *
 * Най-близката точка се търси през 2 индекса с уточняване ±2 и проекция
 * върху съседните сегменти: старото търсене през 8 индекса вземаше y на
 * най-близкия ВЪЗЕЛ и на Raidillon (18 % наклон) съседни клетки правеха
 * стъпала до самия верж.
 *
 * @param {import('./track.js').Track} track
 * @param {number} x
 * @param {number} z
 * @param {{dist: number, half: number, base: number}} out
 * @param {Int32Array} reachBuffer Скреч за индексите в обсег (≥ count / 2 + 1)
 */
function trackBaseAt(track, x, z, out, reachBuffer) {
    const { xs, ys, zs, nx, nz, halfWidths, bankSlope, spacing, count } = track;
    const reachSq = SECTION_REACH * SECTION_REACH;

    // Един проход: най-близката точка + кандидатите в обсег за минимума по
    // секции (обикновено под 60 индекса), вместо втори пълен проход по-долу.
    let bestI = 0;
    let bestDistSq = Infinity;
    let reachCount = 0;
    for (let i = 0; i < count; i += 2) {
        const dx = x - xs[i];
        const dz = z - zs[i];
        const distSq = dx * dx + dz * dz;
        if (distSq < bestDistSq) {
            bestDistSq = distSq;
            bestI = i;
        }
        if (distSq <= reachSq) {
            reachBuffer[reachCount++] = i;
        }
    }
    for (let k = -2; k <= 2; k++) {
        const i = (((bestI + k) % count) + count) % count;
        const dx = x - xs[i];
        const dz = z - zs[i];
        const distSq = dx * dx + dz * dz;
        if (distSq < bestDistSq) {
            bestDistSq = distSq;
            bestI = i;
        }
    }

    // Проекция върху сегмента към предишната и към следващата точка; печели
    // по-близкият. Полуширината и банкингът се интерполират по проекцията,
    // така че между два възела няма скок.
    const prev = (bestI - 1 + count) % count;
    const next = (bestI + 1) % count;
    let bestT = 0;
    let bestFrom = bestI;
    let bestTo = bestI;
    let bestSegDistSq = bestDistSq;

    for (let side = 0; side < 2; side++) {
        const from = side === 0 ? prev : bestI;
        const to = side === 0 ? bestI : next;
        const ex = xs[to] - xs[from];
        const ez = zs[to] - zs[from];
        const lenSq = ex * ex + ez * ez;
        if (lenSq === 0) {
            continue;
        }
        const tt = clamp01(((x - xs[from]) * ex + (z - zs[from]) * ez) / lenSq);
        const dx = x - (xs[from] + ex * tt);
        const dz = z - (zs[from] + ez * tt);
        const distSq = dx * dx + dz * dz;
        if (distSq < bestSegDistSq) {
            bestSegDistSq = distSq;
            bestT = tt;
            bestFrom = from;
            bestTo = to;
        }
    }

    const px = xs[bestFrom] + (xs[bestTo] - xs[bestFrom]) * bestT;
    const pz = zs[bestFrom] + (zs[bestTo] - zs[bestFrom]) * bestT;
    const dist = Math.sqrt(bestSegDistSq);
    const half = halfWidths[bestFrom] + (halfWidths[bestTo] - halfWidths[bestFrom]) * bestT;
    const bank = bankSlope[bestFrom] + (bankSlope[bestTo] - bankSlope[bestFrom]) * bestT;

    // Вдлъбнатина на профила: билинейната повърхност между два възела е
    // хорда, а платното в падина минава ПОД хордата. Сваляме базата с
    // най-голямото отклонение на профила под хордата между ±W реда — на
    // равномерен наклон хордата съвпада с платното и нищо не се сваля
    // (обикновен минимум в прозореца би влачил терена с g·W под всеки склон).
    const yLo = ys[(((bestI - VALLEY_WINDOW) % count) + count) % count];
    const yHi = ys[(bestI + VALLEY_WINDOW) % count];
    let valley = 0;
    for (let k = -VALLEY_WINDOW + 1; k < VALLEY_WINDOW; k++) {
        const chord = yLo + (yHi - yLo) * ((k + VALLEY_WINDOW) / (2 * VALLEY_WINDOW));
        const excess = ys[(((bestI + k) % count) + count) % count] - chord;
        if (excess < valley) {
            valley = excess;
        }
    }

    const y = ys[bestFrom] + (ys[bestTo] - ys[bestFrom]) * bestT + valley;

    // Нормалата се мени бавно — тази на най-близкия възел стига за знака.
    const lateral = (x - px) * nx[bestI] + (z - pz) * nz[bestI];
    let base = sectionBase(y, dist, half, lateral, bank);

    // Другите секции в обсег (и сгънатите рамена на същата — остър завой,
    // фиба): същата формула спрямо всяка от тях, печели най-ниската. Точките
    // са през 2 индекса — на 4 m стъпка грешката по разстояние е под 2 m,
    // нищожна срещу спад 0.035 m/m.
    for (let r = 0; r < reachCount; r++) {
        const i = reachBuffer[r];
        const dx = x - xs[i];
        const dz = z - zs[i];
        const d = Math.sqrt(dx * dx + dz * dz);
        const gap = Math.abs(i - bestI);
        const along = Math.min(gap, count - gap) * spacing;
        if (along <= SAME_SECTION * spacing && d >= along * FOLD_RATIO) {
            continue;
        }
        const lat = dx * nx[i] + dz * nz[i];
        const candidate = sectionBase(ys[i], d, halfWidths[i], lat, bankSlope[i]);
        if (candidate < base) {
            base = candidate;
        }
    }

    out.dist = dist;
    out.half = half;
    out.base = base;
}

/**
 * Нивото на банкета на една секция, видяно от точка на разстояние dist от
 * осевата ѝ линия: спадът тръгва от РЪБА на асфалта (не от осевата), за да
 * слизат банкетът и теренът по една права; банкингът накланя и банкета —
 * без този член теренът стоеше хоризонтално около наклоненото платно и
 * зариваше вътрешната му страна с ~1-4 m (гасне отвъд run-off зоната).
 *
 * @param {number} y Височина на осевата линия
 * @param {number} dist Разстояние до осевата линия
 * @param {number} half Полуширина на асфалта
 * @param {number} lateral Знаково странично отместване (по нормалата)
 * @param {number} bank tan(напречен наклон)
 * @returns {number}
 */
function sectionBase(y, dist, half, lateral, bank) {
    const drop = Math.min(Math.max(0, dist - half), 30) * RUNOFF_DROP;
    // От високата страна наклонът продължава само до ръба на банкета (насип
    // — отвъд него теренът не се катери нагоре: на 18° в Зандвоорт възел на
    // 25 m стоеше над самия верж). От ниската страна слиза ПО-СТРЪМНО отвъд
    // ръба: в завой изолиниите на страничното отместване са дъги и
    // билинейната хорда между възлите минава над правия наклон — по-стръмното
    // продължение я държи под ръба на вержа. Гасне с разстоянието.
    const edge = half + RUNOFF_WIDTH;
    const clamped = Math.max(-edge, Math.min(edge, lateral));
    const beyond = lateral - clamped;
    const raise = -(clamped + beyond * BANK_TAIL) * bank;
    const bankTerm = Math.min(raise, -clamped * bank) * (1 - smoothstep(18, 50, dist));

    return y - drop - TERRAIN_SINK + bankTerm;
}

/**
 * Локалната рамка на пристанището (Монако) — копие на decor.js harborFrame,
 * само за потъването на терена. Ако водната плоскост/яхтите се преместят,
 * трябва да се премести и това (waterY, width, depth в circuits.js).
 *
 * @param {import('./track.js').Track} track
 * @param {{along: number, side: number, dist: number, width: number, depth: number, waterY: number}} cfg
 */
function harborFrame(track, cfg) {
    const { xs, zs, tx, tz, nx, nz, spacing, count } = track;
    const steps = cfg.along / spacing;
    const base = Math.floor(steps);
    const frac = steps - base;
    const i = ((base % count) + count) % count;
    const j = (i + 1) % count;
    const px = xs[i] + (xs[j] - xs[i]) * frac;
    const pz = zs[i] + (zs[j] - zs[i]) * frac;
    const cx = px + nx[i] * cfg.side * cfg.dist;
    const cz = pz + nz[i] * cfg.side * cfg.dist;

    return {
        halfW: cfg.width / 2,
        halfD: cfg.depth / 2,
        waterY: cfg.waterY,
        // u по нормалата (сравнява се с halfW), v по тангентата (с halfD).
        toLocal(x, z) {
            const dx = x - cx;
            const dz = z - cz;

            return { u: dx * nx[i] + dz * nz[i], v: dx * tx[i] + dz * tz[i] };
        },
    };
}

// ── Шум ──────────────────────────────────────────────────────────────────

/**
 * Релефният шум в [0, 1]: три октави value noise (340/110/34 m × scale),
 * първата с остри гребени при ridged, разтегната по X при stretch.
 *
 * @param {number} x
 * @param {number} z
 * @param {TerrainLook} look
 * @returns {number}
 */
function reliefNoise(x, z, look) {
    const sx = x / (look.scale * look.stretch);
    const sz = z / look.scale;
    let first = noise2(sx / 340, sz / 340);
    if (look.ridged) {
        first = 1 - Math.abs(2 * first - 1);
    }

    return first * 0.55 + noise2(sx / 110, sz / 110) * 0.3 + noise2(sx / 34, sz / 34) * 0.15;
}

/**
 * Макро-тонът на палитрата (base → accent) в [0, 1] — отместен от релефния
 * шум, за да не съвпадат петната с хълмовете.
 *
 * @param {number} x
 * @param {number} z
 * @returns {number}
 */
function macroTone(x, z) {
    const n =
        noise2((x + 4096) / 340, (z - 4096) / 340) * 0.55 +
        noise2((x + 4096) / 110, (z - 4096) / 110) * 0.3 +
        noise2((x + 4096) / 34, (z - 4096) / 34) * 0.15;

    return clamp01(n * 1.5 - 0.25);
}

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

/** Двумерен value noise с билинейна интерполация, детерминиран. */
function noise2(x, z) {
    const ix = Math.floor(x);
    const iz = Math.floor(z);
    const fx = x - ix;
    const fz = z - iz;
    const sx = fx * fx * (3 - 2 * fx);
    const sz = fz * fz * (3 - 2 * fz);

    const h = (a, b) => hashNoise(a * 157.31 + b * 313.97);
    const v00 = h(ix, iz);
    const v10 = h(ix + 1, iz);
    const v01 = h(ix, iz + 1);
    const v11 = h(ix + 1, iz + 1);

    const a = v00 + (v10 - v00) * sx;
    const b = v01 + (v11 - v01) * sx;

    return a + (b - a) * sz;
}

// ── Дребни числови помощници ─────────────────────────────────────────────

function smoothstep(edge0, edge1, v) {
    const t = clamp01((v - edge0) / (edge1 - edge0));

    return t * t * (3 - 2 * t);
}

function clamp01(v) {
    return v < 0 ? 0 : v > 1 ? 1 : v;
}

function clampInt(v, min, max) {
    return Math.max(min, Math.min(max, Math.round(v)));
}

/** Число > 0 или резервът (пази от NaN/0 от непълен look). */
function positive(v, fallback) {
    return Number.isFinite(v) && v > 0 ? v : fallback;
}

function nonNegative(v, fallback) {
    return Number.isFinite(v) && v >= 0 ? v : fallback;
}

/** Отношение на канали, ограничено, за да не избухва при почти черна база. */
function safeRatio(a, b) {
    if (b < 0.02) {
        return 1;
    }

    return Math.min(1.5, Math.max(0.6, a / b));
}
