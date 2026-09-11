/**
 * Декорът, който превръща „трасе с правилната форма" в разпознаваема писта:
 * питлейн комплекс с гаражи, стартова решетка, гантри със светлини, спирачни
 * табели, чакълени зони, гумени/TecPro бариери с предпазни огради, реклами,
 * мантинели (градски писти), маршалски постове и специалните ориентири
 * (виенското колело на Сузука, пристанището на Монако).
 *
 * Всичко е процедурно от осевата линия + CircuitStyle (circuits.js). Никакви
 * външни модели; геометрията се слива/инстанцира/батчва. Теренът живее в
 * terrain.js — тук всеки реквизит само СТЪПВА на него през общия семплер
 * (groundY): банкетът следва правилото на тревата, отвъд него говори теренът.
 *
 * Culling: всеки InstancedMesh има сфера и frustumCulled=true, дългите ленти
 * (стени, огради, реклами) са BatchedMesh на парчета по ~120 реда с
 * per-object culling — сенчестият pass (WebGLShadowMap прескача frustum теста
 * при frustumCulled=false) вече не рисува цялата обиколка всеки кадър.
 */

import * as THREE from 'three';
import { mergeGeometries } from 'three/examples/jsm/utils/BufferGeometryUtils.js';
import { RoundedBoxGeometry } from 'three/examples/jsm/geometries/RoundedBoxGeometry.js';
import { Water } from 'three/examples/jsm/objects/Water.js';
import { curvatureRanges } from './sim.js';
import { applyPatch } from './materialPatch.js';
import { buildHoardings } from './hoardings.js';
import { makeDetailMaps, makeNoiseNormalMap } from './facades.js';

// Семплерът на терена е собственост на terrain.js; re-export за mesh.js,
// който го взима оттук от първия ден.
export { createTerrainSampler } from './terrain.js';

// Синхронизирани с mesh.js — банкетът и реквизитът трябва да се снаждат.
const RUNOFF_DROP = 0.035;
const RUNOFF_WIDTH = 8;

/** Височината на тревата спрямо асфалта (Y.grass в mesh.js). */
const Y_GRASS = -0.12;

/** Метри на една плочка текстура по метричните UV (общият договор на вълната). */
const TILE = 4;

/** Редове на един chunk в BatchedMesh (~120 реда ≈ 480 m при spacing 4). */
const CHUNK_ROWS = 120;

const DECOR = {
    gravel: 0xb9a878,
    sand: 0xf0e2c0,
    asphaltRunoff: 0x46464c,
    pitLane: 0x3d3d44,
    pitApron: 0x55555c,
    pitWallTop: 0xd9dde2,
    pitWallBottom: 0x8f959c,
    garageBody: 0xb6b9bf,
    garageRoof: 0x585d66,
    gantry: 0x33373d,
    sausage: 0xe07b1a,
    tyre: 0x1c1c1f,
    tyreAccent: [0xd0d0d4, 0xc23a32],
    belt: 0x1a1a1d,
    tecpro: [0xd42a26, 0xf0f0f0],
    wheelSteel: 0xe8eaee,
    water: 0x14507a,
    yacht: 0xf2f3f5,
    concrete: 0xb3ada0,
    concreteLow: 0x8f8a7e,
};

/** Височинно наслояване (виж Y в mesh.js) — под ръбовите линии (0.012). */
const Y_GRID = 0.013;
const Y_GRAVEL = -0.1;

/**
 * Общото време на плата (знамена): едно uniform-обектче, споделено от всички
 * знаменни материали, тиктакано от animations. Експортирано, за да може
 * mesh.js (карираният флаг) да ползва същия ритъм.
 */
export const FLAG_UNIFORMS = { uTime: { value: 0 } };

/**
 * Сглобява целия декор за пистата.
 *
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @param {object} sampler Общият терен (terrain.js createTerrainSampler): heightAt(x, z)
 * @param {{lowPower?: boolean, quality?: object, night?: boolean, maxAniso?: number, look?: object, getCarPosition?: (() => THREE.Vector3)|null}} [options]
 * @returns {DecorResult}
 */
export function buildCircuitDecor(track, circuit, sampler, options = {}) {
    const look = options.look ?? circuit.look ?? {};
    const opts = {
        lowPower: options.lowPower === true,
        quality: options.quality ?? null,
        night: options.night ?? (circuit.atmosphere?.night === true || look.preset === 'night'),
        maxAniso: Math.max(1, options.maxAniso ?? 1),
        look,
        sampler,
        getCarPosition: typeof options.getCarPosition === 'function' ? options.getCarPosition : null,
        // Телефонът не хвърля сенки от декора (Game чисти и без това) и не
        // носи детайлни карти — един fetch по-малко на фрагмент бетон.
        castShadow: options.lowPower !== true,
        detail: options.lowPower === true ? null : makeDetailMaps(),
    };

    const group = new THREE.Group();
    const animations = [];
    const disposables = [];

    const straight = findStartStraight(track);
    const pit = buildPitComplex(track, circuit, straight, opts);
    group.add(pit.group);
    if (pit.animate) {
        animations.push(pit.animate);
    }

    // Материалът на чакъла се подменя от Game.#loadTrackTextures с истинска
    // PBR текстура (public/game-textures/gravel), ако се зареди навреме.
    let gravelMaterial = null;

    group.add(buildGridSlots(track, straight));

    const gantry = buildStartGantry(track, circuit, opts);
    group.add(gantry.group);

    // Мостове: пасарелка над най-дългата права (без градските писти) и
    // автоматично детектираните грейд-сепарации (осмицата на Сузука).
    if (!circuit.streetWalls) {
        const footbridge = buildFootbridge(track, pit, opts);
        if (footbridge) {
            group.add(footbridge);
        }
    }
    const crossover = buildCrossoverBridges(track, opts);
    for (const bridge of crossover.meshes) {
        group.add(bridge);
    }

    if (circuit.runoff !== 'none') {
        const gravel = buildRunoffZones(track, circuit, opts);
        if (gravel) {
            group.add(gravel);
            if (circuit.runoff !== 'asphalt') {
                gravelMaterial = gravel.material;
            }
        }

        const barriers = circuit.runoff === 'asphalt'
            ? buildTecproBarriers(track, opts)
            : buildTyreStacks(track, opts);
        for (const mesh of barriers) {
            group.add(mesh);
        }
    }

    for (const mesh of buildMarkerBoards(track, opts)) {
        group.add(mesh);
    }

    if (circuit.sausageKerbs) {
        const sausages = buildSausageKerbs(track);
        if (sausages) {
            group.add(sausages);
        }
    }

    if (circuit.streetWalls) {
        for (const mesh of buildStreetWalls(track, circuit, pit, opts)) {
            group.add(mesh);
        }
    }

    // Тунелът на Монако — галерия над сегмента от конфига (from/to в метри).
    let tunnel = null;
    if (circuit.tunnel) {
        tunnel = buildTunnel(track, circuit.tunnel, opts);
        group.add(tunnel.group);
    }

    // Информационните пана са само отвън на завоите и зад зоната за
    // сигурност; правите остават чисти и четими при висока скорост.
    const hoardings = buildHoardings(track, pit, {
        segments: hoardingSegments(track),
        ground: (meters, offset) => groundYAt(track, sampler, meters, offset),
        batch: (geometries, material, flags) => batchChunks(geometries, material, flags),
        grandstand: circuit.startGrandstands ? { sign: -pit.sign, fromMeters: -20, toMeters: 150 } : null,
        tunnel: tunnel ? { rowFrom: tunnel.rowFrom, rowTo: tunnel.rowTo } : null,
        streetWalls: circuit.streetWalls === true,
        slug: track.slug,
        look,
        maxAniso: opts.maxAniso,
        castShadow: opts.castShadow,
    });
    if (hoardings) {
        group.add(hoardings.mesh);
    }

    // Мраморчетата на изхода на завоите — физическите топчета гума, които
    // асфалтовият шейдър (тъмната ивица) не може да даде.
    const marbles = buildMarbles(track, opts);
    if (marbles) {
        group.add(marbles);
    }

    if (circuit.landmark?.type === 'ferris_wheel') {
        const wheel = buildFerrisWheel(track, circuit, sampler);
        group.add(wheel.group);
        animations.push(wheel.animate);
    }

    if (circuit.landmark?.type === 'harbor') {
        const harbor = buildHarbor(track, circuit, opts);
        group.add(harbor.group);
        animations.push(harbor.animate);
        disposables.push(...harbor.disposables);
    }

    // Развети знамена край стартовата зона — животът в кадъра, който продава
    // сцената. Български трикольори между пъстрите: публиката ни е нашата.
    if (circuit.startGrandstands || circuit.streetWalls) {
        const flags = buildStartFlags(track, pit, sampler);
        group.add(flags.group);
        animations.push(flags.animate);
    }

    // Общото време на плата — един тик за всички знамена (и карирания в mesh.js).
    animations.push((dt) => {
        FLAG_UNIFORMS.uTime.value = (FLAG_UNIFORMS.uTime.value + dt) % 1000;
    });

    // Маршалски постове на тежките завои — Game развява жълтия флаг на
    // най-близкия пост, докато тече връщането на пистата.
    const marshals = buildMarshalPosts(track, sampler);
    group.add(marshals.group);
    const marshalPosts = marshals.posts;

    // Нощно състезание: прожекторните кули СА светлината (директната в Game
    // е сборният им ефект) — тук са само визуалните пилони с греещи глави;
    // ореолите/конусите ги прави nightLights по списъка floodlights.
    let floodlights = [];
    if (opts.night) {
        const towers = buildFloodlightTowers(track, pit, circuit.startGrandstands === true, opts);
        group.add(towers.group);
        floodlights = towers.towers;
    }

    // ТВ хеликоптерът кръжи над всяка писта — broadcast усещане на хоризонта.
    const helicopter = buildHelicopter(track, sampler, opts);
    group.add(helicopter.group);
    animations.push(helicopter.animate);

    const grandstandBounds = computeGrandstandBounds(track, circuit, pit, sampler);

    const result = {
        group,
        startLights: gantry.lights,
        animations,
        gravelMaterial,
        marshalPosts,
        // Диапазонът на пит комплекса (редове спрямо ред 0 + страна) — mesh.js
        // го ползва, за да не слага стълбчета/трибуни върху питлейна.
        pitRange: { from: pit.from, to: pit.to, sign: pit.sign },
        floodlights,
        crossings: crossover.crossings,
        grandstandBounds,
        helicopter: helicopter.group,
        tunnel: tunnel ? { from: circuit.tunnel.from, to: circuit.tunnel.to, rowFrom: tunnel.rowFrom, rowTo: tunnel.rowTo } : null,
        disposables,
        setGantryText: gantry.setText,
    };

    // Същите полета и върху userData на групата: Game може да ги чете без
    // mesh.js да пренася всяко поотделно.
    Object.assign(group.userData, result, { group: undefined });

    return result;
}

/**
 * @typedef {object} DecorResult
 * @property {THREE.Group} group
 * @property {THREE.MeshStandardMaterial[]} startLights Петте колони светлини (Game.#launchFrame)
 * @property {Array<(dt: number) => void>} animations
 * @property {THREE.MeshStandardMaterial|null} gravelMaterial
 * @property {Array<{index: number, pivot: THREE.Group, panel: THREE.MeshStandardMaterial}>} marshalPosts
 * @property {{from: number, to: number, sign: number}} pitRange
 * @property {Array<{x: number, y: number, z: number, dirX: number, dirZ: number, index: number, side: number}>} floodlights
 * @property {Array<{index: number, lower: number, x: number, z: number}>} crossings
 * @property {Array<{cx: number, cz: number, radius: number, top: number, index: number}>} grandstandBounds
 * @property {THREE.Group} helicopter
 * @property {{from: number, to: number, rowFrom: number, rowTo: number}|null} tunnel
 * @property {Array<{dispose: () => void}>} disposables Ресурси извън обхвата на Game.dispose traverse (Water RT)
 * @property {(text: string) => void} setGantryText LED таблото на гантрито
 */

// ── Земя и рамка ─────────────────────────────────────────────────────────

/**
 * Височината на семплера — terrain.js я дава като heightAt (наследено от
 * стария семплер тук); height се приема като синоним, за да не зависи
 * декорът от името.
 *
 * @param {object} sampler
 * @param {number} x
 * @param {number} z
 * @returns {number}
 */
function sampleHeight(sampler, x, z) {
    return typeof sampler.heightAt === 'function' ? sampler.heightAt(x, z) : sampler.height(x, z);
}

/**
 * Земята под реквизит на ред i при странично отместване: в банкета —
 * правилото на тревата (mesh.js: ys − 0.12, спад 0.035 m/m ОТ РЪБА на
 * асфалта, банкинг), отвъд него — теренът. Единственият източник на истина
 * за „стои на земята"; mesh.js го ползва за стълбчетата.
 *
 * @param {import('./track.js').Track} track
 * @param {object} sampler
 * @param {number} i Индекс на ред (0..count-1)
 * @param {number} offset Метри по нормалата (знаково)
 * @returns {number}
 */
export function groundY(track, sampler, i, offset) {
    const half = track.halfWidths[i];
    const abs = Math.abs(offset);

    if (abs <= half + RUNOFF_WIDTH) {
        return track.ys[i] + Y_GRASS - Math.max(0, abs - half) * RUNOFF_DROP - offset * track.bankSlope[i];
    }

    return sampleHeight(sampler, track.xs[i] + track.nx[i] * offset, track.zs[i] + track.nz[i] * offset);
}

/**
 * groundY на произволни метри по обиколката (линейно между редовете).
 *
 * @param {import('./track.js').Track} track
 * @param {object} sampler
 * @param {number} meters Може да е отрицателно
 * @param {number} offset
 * @returns {number}
 */
export function groundYAt(track, sampler, meters, offset) {
    const steps = meters / track.spacing;
    const base = Math.floor(steps);
    const frac = steps - base;
    const i = ((base % track.count) + track.count) % track.count;
    const j = (i + 1) % track.count;
    const a = groundY(track, sampler, i, offset);
    const b = groundY(track, sampler, j, offset);

    return a + (b - a) * frac;
}

/**
 * Точка на `meters` метра по обиколката (интерполирана между осевите точки),
 * с локалната рамка тангента/нормала.
 *
 * @param {import('./track.js').Track} track
 * @param {number} meters Може да е отрицателно (преди старта)
 * @returns {{x: number, y: number, z: number, tx: number, tz: number, nx: number, nz: number, i: number}}
 */
function pointAt(track, meters) {
    const { xs, ys, zs, tx, tz, nx, nz, spacing, count } = track;
    const steps = meters / spacing;
    const base = Math.floor(steps);
    const frac = steps - base;
    const i = ((base % count) + count) % count;
    const j = (i + 1) % count;

    return {
        x: xs[i] + (xs[j] - xs[i]) * frac,
        y: ys[i] + (ys[j] - ys[i]) * frac,
        z: zs[i] + (zs[j] - zs[i]) * frac,
        tx: tx[i],
        tz: tz[i],
        nx: nx[i],
        nz: nz[i],
        i,
    };
}

// ── Геометрични помощници ────────────────────────────────────────────────

/**
 * Общите атрибути на всяка лента/стена/панел: метрични UV и аLateral/aAlong/
 * aHalfWidth (договорът с повърхностния шейдър), еднакви навсякъде, за да са
 * геометриите съвместими при mergeGeometries и в BatchedMesh.
 *
 * @param {THREE.BufferGeometry} geometry
 * @param {Float32Array} positions
 * @param {Float32Array} uvs
 * @param {Float32Array} colors
 * @param {Float32Array} lateral
 * @param {Float32Array} along
 * @param {Float32Array} halfW
 * @param {Uint32Array|Uint16Array} indices
 * @returns {THREE.BufferGeometry}
 */
function assembleGeometry(geometry, positions, uvs, colors, lateral, along, halfW, indices) {
    geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    geometry.setAttribute('uv', new THREE.BufferAttribute(uvs, 2));
    geometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));
    geometry.setAttribute('aLateral', new THREE.BufferAttribute(lateral, 1));
    geometry.setAttribute('aAlong', new THREE.BufferAttribute(along, 1));
    geometry.setAttribute('aHalfWidth', new THREE.BufferAttribute(halfW, 1));
    geometry.setIndex(new THREE.BufferAttribute(indices, 1));
    geometry.computeVertexNormals();
    geometry.computeBoundingSphere();

    return geometry;
}

/**
 * Лента между две странични отмествания за диапазон от редове (виж ribbonMesh
 * в mesh.js — това е обобщението ѝ: частичен диапазон и отмествания-функции).
 * Спадът `drop` се мери ОТ РЪБА на асфалта (правилото на банкета), UV са
 * метрични (u = offset/4, v = метри/4).
 *
 * @param {import('./track.js').Track} track
 * @param {number} rowFrom Включително; може да е отрицателен (wrap по модул)
 * @param {number} rowTo Включително
 * @param {number|((row: number, i: number) => number)} offsetA
 * @param {number|((row: number, i: number) => number)} offsetB
 * @param {number} y
 * @param {{color: number, variation?: number, drop?: number}} options
 * @returns {THREE.BufferGeometry}
 */
export function stripGeometry(track, rowFrom, rowTo, offsetA, offsetB, y, options) {
    const { xs, ys, zs, nx, nz, count, spacing, curvature, bankSlope, halfWidths } = track;

    const rows = rowTo - rowFrom + 1;
    const positions = new Float32Array(rows * 2 * 3);
    const colors = new Float32Array(rows * 2 * 3);
    const uvs = new Float32Array(rows * 2 * 2);
    const lateral = new Float32Array(rows * 2);
    const along = new Float32Array(rows * 2);
    const halfW = new Float32Array(rows * 2);
    const indices = new Uint32Array((rows - 1) * 6);

    const base = new THREE.Color(options.color);
    const variation = options.variation ?? 0;
    const drop = options.drop ?? 0;

    // Навивката зависи от посоката на отместванията: при offsetB < offsetA
    // (лента от отрицателната страна на нормалата — чакъл на десен завой,
    // питлейн отляво) фиксираният ред триъгълници би гледал НАДОЛУ и лентата
    // изчезва при backface culling. Меря в средата на диапазона — в краищата
    // клиновете (пит вход/изход) дават нула.
    const midRow = Math.floor((rowFrom + rowTo) / 2);
    const midI = ((midRow % count) + count) % count;
    const evalOffset = (fn, row, i) => (typeof fn === 'function' ? fn(row, i) : fn);
    const flipWinding = evalOffset(offsetB, midRow, midI) < evalOffset(offsetA, midRow, midI);

    for (let r = 0; r < rows; r++) {
        const row = rowFrom + r;
        const i = ((row % count) + count) % count;

        // Клампа по радиуса на кривината — както в ribbonMesh: лента, по-широка
        // от 80 % на радиуса, се сгъва навътре отвъд центъра на завоя.
        const k = curvature[i];
        const innerLimit = k !== 0 ? 0.8 / k : 0;

        for (let side = 0; side < 2; side++) {
            const fn = side === 0 ? offsetA : offsetB;
            let offset = evalOffset(fn, row, i);
            if (k > 0) {
                offset = Math.min(offset, innerLimit);
            } else if (k < 0) {
                offset = Math.max(offset, innerLimit);
            }

            const vertex = r * 2 + side;
            const vi = vertex * 3;
            positions[vi] = xs[i] + nx[i] * offset;
            // Банкингът накланя лентата напречно, като платното.
            positions[vi + 1] =
                ys[i] + y - Math.max(0, Math.abs(offset) - halfWidths[i]) * drop - offset * bankSlope[i];
            positions[vi + 2] = zs[i] + nz[i] * offset;

            uvs[vertex * 2] = offset / TILE;
            uvs[vertex * 2 + 1] = (row * spacing) / TILE;
            lateral[vertex] = offset;
            along[vertex] = row * spacing;
            halfW[vertex] = halfWidths[i];

            const noise = variation > 0 ? (hashNoise(i * 2 + side) - 0.5) * variation : 0;
            colors[vi] = clamp01(base.r + noise);
            colors[vi + 1] = clamp01(base.g + noise);
            colors[vi + 2] = clamp01(base.b + noise);
        }
    }

    for (let r = 0; r < rows - 1; r++) {
        const a = r * 2;
        const t = r * 6;
        if (flipWinding) {
            indices[t] = a;
            indices[t + 1] = a + 2;
            indices[t + 2] = a + 1;
            indices[t + 3] = a + 1;
            indices[t + 4] = a + 2;
            indices[t + 5] = a + 3;
        } else {
            indices[t] = a;
            indices[t + 1] = a + 1;
            indices[t + 2] = a + 2;
            indices[t + 3] = a + 1;
            indices[t + 4] = a + 3;
            indices[t + 5] = a + 2;
        }
    }

    return assembleGeometry(new THREE.BufferGeometry(), positions, uvs, colors, lateral, along, halfW, indices);
}

/**
 * Вертикална стена по трасето: лента от долен до горен ръб.
 *
 * @param {import('./track.js').Track} track
 * @param {number} rowFrom
 * @param {number} rowTo
 * @param {number|((row: number, i: number) => number)} offsetFn
 * @param {number} height Горен ръб, метри над основата
 * @param {number} colorTop
 * @param {number} colorBottom
 * @param {number} bottom Долен ръб спрямо основата (по подразбиране леко вкопан)
 * @param {{uvTile?: number|null, v0?: number, v1?: number, faceInward?: boolean, groundFn?: ((row: number, i: number, offset: number) => number)|null}} [options]
 *        uvTile: метри на едно повторение по u (null → 0..1 по цялата лента);
 *        faceInward: лицето към трасето (иначе към +нормалата);
 *        groundFn: основата (по подразбиране платното + банкинг).
 * @returns {THREE.BufferGeometry}
 */
export function wallGeometry(track, rowFrom, rowTo, offsetFn, height, colorTop, colorBottom, bottom = -0.35, options = {}) {
    const { xs, ys, zs, nx, nz, count, spacing, bankSlope, halfWidths } = track;

    const rows = rowTo - rowFrom + 1;
    const positions = new Float32Array(rows * 2 * 3);
    const colors = new Float32Array(rows * 2 * 3);
    const uvs = new Float32Array(rows * 2 * 2);
    const lateral = new Float32Array(rows * 2);
    const along = new Float32Array(rows * 2);
    const halfW = new Float32Array(rows * 2);
    const indices = new Uint32Array((rows - 1) * 6);

    const top = new THREE.Color(colorTop);
    const low = new THREE.Color(colorBottom);
    const uvTile = options.uvTile ?? null;
    const v0 = options.v0 ?? 0;
    const v1 = options.v1 ?? 1;
    const groundFn = options.groundFn ?? null;
    const evalOffset = (row, i) => (typeof offsetFn === 'function' ? offsetFn(row, i) : offsetFn);

    for (let r = 0; r < rows; r++) {
        const row = rowFrom + r;
        const i = ((row % count) + count) % count;
        const offset = evalOffset(row, i);

        const bx = xs[i] + nx[i] * offset;
        const bz = zs[i] + nz[i] * offset;
        const baseY = groundFn ? groundFn(row, i, offset) : ys[i] - offset * bankSlope[i];

        const vi = r * 6;
        positions[vi] = bx;
        positions[vi + 1] = baseY + bottom;
        positions[vi + 2] = bz;
        positions[vi + 3] = bx;
        positions[vi + 4] = baseY + height;
        positions[vi + 5] = bz;

        colors[vi] = low.r;
        colors[vi + 1] = low.g;
        colors[vi + 2] = low.b;
        colors[vi + 3] = top.r;
        colors[vi + 4] = top.g;
        colors[vi + 5] = top.b;

        const u = uvTile === null ? r / Math.max(1, rows - 1) : (r * spacing) / uvTile;
        const uvi = r * 4;
        uvs[uvi] = u;
        uvs[uvi + 1] = v0;
        uvs[uvi + 2] = u;
        uvs[uvi + 3] = v1;

        lateral[r * 2] = offset;
        lateral[r * 2 + 1] = offset;
        along[r * 2] = row * spacing;
        along[r * 2 + 1] = row * spacing;
        halfW[r * 2] = halfWidths[i];
        halfW[r * 2 + 1] = halfWidths[i];
    }

    // Навивка (a, a+2, a+1): нормала t×up = +n. Лице към трасето означава
    // −sign(offset)·n → обръщаме реда, когато стената е от +страната.
    const midRow = Math.floor((rowFrom + rowTo) / 2);
    const flip = options.faceInward === true && evalOffset(midRow, ((midRow % count) + count) % count) > 0;

    for (let r = 0; r < rows - 1; r++) {
        const a = r * 2;
        const t = r * 6;
        if (flip) {
            indices[t] = a;
            indices[t + 1] = a + 1;
            indices[t + 2] = a + 2;
            indices[t + 3] = a + 1;
            indices[t + 4] = a + 3;
            indices[t + 5] = a + 2;
        } else {
            indices[t] = a;
            indices[t + 1] = a + 2;
            indices[t + 2] = a + 1;
            indices[t + 3] = a + 1;
            indices[t + 4] = a + 2;
            indices[t + 5] = a + 3;
        }
    }

    return assembleGeometry(new THREE.BufferGeometry(), positions, uvs, colors, lateral, along, halfW, indices);
}

/**
 * Четириъгълник от четири световни точки (обхождани по контура), със същите
 * атрибути като лентите — за крайни стени и капаци, които се сливат с тях.
 *
 * @param {Array<[number, number, number]>} corners
 * @param {number} color
 * @returns {THREE.BufferGeometry}
 */
function quadGeometry(corners, color) {
    const positions = new Float32Array(12);
    const colors = new Float32Array(12);
    const uvs = new Float32Array([0, 0, 1, 0, 1, 1, 0, 1]);
    const c = new THREE.Color(color);
    for (let k = 0; k < 4; k++) {
        positions[k * 3] = corners[k][0];
        positions[k * 3 + 1] = corners[k][1];
        positions[k * 3 + 2] = corners[k][2];
        colors[k * 3] = c.r;
        colors[k * 3 + 1] = c.g;
        colors[k * 3 + 2] = c.b;
    }

    return assembleGeometry(
        new THREE.BufferGeometry(),
        positions,
        uvs,
        colors,
        new Float32Array(4),
        new Float32Array(4),
        new Float32Array(4),
        new Uint32Array([0, 1, 2, 0, 2, 3])
    );
}

/**
 * Диапазон редове → парчета по CHUNK_ROWS (съседните споделят граничния ред,
 * за да няма процеп).
 *
 * @param {number} rowFrom
 * @param {number} rowTo
 * @returns {Array<[number, number]>}
 */
function chunkRows(rowFrom, rowTo) {
    const out = [];
    for (let a = rowFrom; a < rowTo; a += CHUNK_ROWS) {
        out.push([a, Math.min(rowTo, a + CHUNK_ROWS)]);
    }

    return out;
}

/**
 * Няколко световни геометрии → ЕДИН BatchedMesh с per-object frustum culling
 * (един draw call; сенчестият pass и камерата виждат само парчетата в кадър).
 * Капацитетите се смятат от подадените парчета; те се dispose-ват след
 * копирането. Всички парчета трябва да имат еднакви атрибути.
 *
 * @param {THREE.BufferGeometry[]} geometries
 * @param {THREE.Material} material
 * @param {{castShadow?: boolean}} [flags]
 * @returns {THREE.BatchedMesh}
 */
export function batchChunks(geometries, material, flags = {}) {
    let vertices = 0;
    let indices = 0;
    for (const geometry of geometries) {
        vertices += geometry.attributes.position.count;
        indices += geometry.index ? geometry.index.count : 0;
    }

    const mesh = new THREE.BatchedMesh(geometries.length, Math.max(3, vertices), Math.max(3, indices), material);
    // Без сортиране: парчетата са непрозрачни и статични — сортирането е
    // само CPU време по кадър.
    mesh.sortObjects = false;
    for (const geometry of geometries) {
        const id = mesh.addGeometry(geometry);
        mesh.addInstance(id);
        geometry.dispose();
    }
    mesh.computeBoundingSphere();
    mesh.castShadow = flags.castShadow === true;
    mesh.receiveShadow = true;

    return mesh;
}

// ── Огради, фигури, знамена (общи с mesh.js) ─────────────────────────────

/** Кеширана текстура на мрежата (една за сесията; three я качва наново след dispose). */
let fenceTexture = null;

/**
 * Защитна ограда (catch fence): полупрозрачна мрежа върху лента от стена.
 * Ползва се пред трибуните (mesh.js), върху пит стената, по градските
 * мантинели и зад гумените стени.
 *
 * @param {import('./track.js').Track} track
 * @param {number} rowFrom
 * @param {number} rowTo
 * @param {number|((row: number, i: number) => number)} offsetFn
 * @param {number} bottom Долен ръб над основата
 * @param {number} top Горен ръб над основата
 * @param {{maxAniso?: number, groundFn?: ((row: number, i: number, offset: number) => number)|null}} [options]
 * @returns {THREE.Mesh}
 */
export function fenceMesh(track, rowFrom, rowTo, offsetFn, bottom, top, options = {}) {
    const mesh = new THREE.Mesh(fenceGeometry(track, rowFrom, rowTo, offsetFn, bottom, top, options.groundFn ?? null), fenceMaterial(options));
    mesh.receiveShadow = true;

    return mesh;
}

/**
 * Геометрия на ограда: стена с метрични UV (u = метри/6, едно повторение =
 * един панел между два стълба).
 */
function fenceGeometry(track, rowFrom, rowTo, offsetFn, bottom, top, groundFn) {
    return wallGeometry(track, rowFrom, rowTo, offsetFn, top, 0xffffff, 0xffffff, bottom, { uvTile: 6, groundFn });
}

/**
 * Материалът на оградата: alphaTest реже фона (и в сенчестия pass), а
 * alphaToCoverage омекотява ръба под MSAA (и телефонът, и composer-ът
 * рендерират с MSAA от вълна 0). Не хвърля сянка — жиците са под texel.
 *
 * @param {{maxAniso?: number}} [options]
 * @returns {THREE.MeshStandardMaterial}
 */
function fenceMaterial(options = {}) {
    const texture = makeFenceTexture();
    texture.anisotropy = Math.max(texture.anisotropy, options.maxAniso ?? 1);

    return new THREE.MeshStandardMaterial({
        map: texture,
        alphaTest: 0.2,
        alphaToCoverage: true,
        side: THREE.DoubleSide,
        metalness: 0.4,
        roughness: 0.6,
    });
}

/**
 * Текстура на телена мрежа: диагонални нишки по 3 px + горна греда + стълб на
 * ръба на всяко повторение, върху прозрачен фон. Singleton — четири
 * консуматора деляха четири еднакви канваса.
 *
 * @returns {THREE.CanvasTexture}
 */
function makeFenceTexture() {
    if (fenceTexture) {
        return fenceTexture;
    }

    const size = 256;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');

    ctx.clearRect(0, 0, size, size);
    ctx.strokeStyle = 'rgba(70, 74, 80, 0.95)';
    ctx.lineWidth = 3;

    // Ромбовидна мрежа: два комплекта диагонали.
    for (let d = -size; d < size * 2; d += 32) {
        ctx.beginPath();
        ctx.moveTo(d, 0);
        ctx.lineTo(d + size, size);
        ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(d + size, 0);
        ctx.lineTo(d, size);
        ctx.stroke();
    }

    // Стълб и горна греда.
    ctx.fillStyle = 'rgba(52, 56, 62, 1)';
    ctx.fillRect(0, 0, 10, size);
    ctx.fillRect(0, 0, size, 9);

    fenceTexture = new THREE.CanvasTexture(canvas);
    fenceTexture.wrapS = THREE.RepeatWrapping;
    fenceTexture.wrapT = THREE.ClampToEdgeWrapping;

    return fenceTexture;
}

/**
 * Човешка фигура (крака + туловище + глава) в един не-индексиран меш с
 * vertex цветове — маршали, пит екипи, карираният маршал (mesh.js).
 *
 * @param {number} suitColor
 * @param {number} [headColor]
 * @param {number} [legColor]
 * @returns {THREE.BufferGeometry}
 */
export function makeFigureGeometry(suitColor, headColor = 0xe0a884, legColor = 0x24272c) {
    const legs = new THREE.BoxGeometry(0.32, 0.75, 0.24);
    legs.translate(0, 0.38, 0);
    paintGeometryFlat(legs, legColor);
    const torso = new THREE.BoxGeometry(0.42, 0.58, 0.28);
    torso.translate(0, 1.03, 0);
    paintGeometryFlat(torso, suitColor);
    const head = new THREE.IcosahedronGeometry(0.12, 0);
    head.translate(0, 1.48, 0);
    paintGeometryFlat(head, headColor);

    const legsFlat = legs.toNonIndexed();
    legs.dispose();
    const torsoFlat = torso.toNonIndexed();
    torso.dispose();

    const figure = mergeGeometries([legsFlat, torsoFlat, head], false);
    legsFlat.dispose();
    torsoFlat.dispose();
    head.dispose();

    return figure;
}

/**
 * Материал на плат: кръпка по върховете — вълна по дължината на знамето,
 * тежест 0 при пръта и 1 на свободния ръб, фаза от световната позиция (един
 * материал за много знамена не вее в синхрон). uTime е споделеният
 * FLAG_UNIFORMS.uTime. Приема текстура или цвят; vertexColors при нужда.
 *
 * @param {THREE.Texture|number|null} mapOrColor
 * @param {{width?: number, vertexColors?: boolean, side?: number, phase?: number}} [options]
 * @returns {THREE.MeshStandardMaterial}
 */
export function makeFlagMaterial(mapOrColor, options = {}) {
    const isTexture = mapOrColor !== null && typeof mapOrColor === 'object' && mapOrColor.isTexture === true;
    const material = new THREE.MeshStandardMaterial({
        map: isTexture ? mapOrColor : null,
        color: isTexture || mapOrColor === null ? 0xffffff : mapOrColor,
        vertexColors: options.vertexColors === true,
        side: options.side ?? THREE.DoubleSide,
        roughness: 0.9,
        metalness: 0,
    });

    applyPatch(material, {
        name: 'clothRipple',
        uniforms: {
            uTime: FLAG_UNIFORMS.uTime,
            uPhase: { value: options.phase ?? 0 },
            uFlagWidth: { value: options.width ?? 1.3 },
        },
        vertexHead: 'uniform float uTime;\nuniform float uPhase;\nuniform float uFlagWidth;',
        replace: [
            [
                'begin_vertex',
                '#include <begin_vertex>\n' +
                    '\t{\n' +
                    '\t\tfloat w = clamp(position.x / uFlagWidth, 0.0, 1.0);\n' +
                    '\t\tfloat ph = uPhase + (modelMatrix[3].x + modelMatrix[3].z) * 0.7;\n' +
                    '\t\ttransformed.z += sin(position.x * 5.0 - uTime * 7.0 + ph) * 0.10 * w;\n' +
                    '\t\ttransformed.y += sin(position.x * 3.0 - uTime * 5.0 + ph * 1.3) * 0.03 * w;\n' +
                    '\t}',
            ],
        ],
    });

    return material;
}

/**
 * Плат на знаме с достатъчно сегменти за вълната; виси от пръта надясно
 * (x от 0 до width).
 *
 * @param {number} width
 * @param {number} height
 * @returns {THREE.PlaneGeometry}
 */
function clothGeometry(width, height) {
    const cloth = new THREE.PlaneGeometry(width, height, 10, 4);
    cloth.translate(width / 2, 0, 0);

    return cloth;
}

/**
 * Плътен vertex color върху цяла геометрия (за merge с обща палитра).
 *
 * @param {THREE.BufferGeometry} geometry
 * @param {number} color
 */
function paintGeometryFlat(geometry, color) {
    const c = new THREE.Color(color);
    const n = geometry.attributes.position.count;
    const colors = new Float32Array(n * 3);

    for (let i = 0; i < n; i++) {
        colors[i * 3] = c.r;
        colors[i * 3 + 1] = c.g;
        colors[i * 3 + 2] = c.b;
    }

    geometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));
}

/**
 * Копие на геометрия, поставено в света (за сливане на еднакви фигури в един
 * меш).
 *
 * @param {THREE.BufferGeometry} geometry
 * @param {number} x
 * @param {number} y
 * @param {number} z
 * @param {number} yaw
 * @returns {THREE.BufferGeometry}
 */
function placedCopy(geometry, x, y, z, yaw) {
    const copy = geometry.clone();
    copy.rotateY(yaw);
    copy.translate(x, y, z);

    return copy;
}

/**
 * Сфера + culling за напълнен InstancedMesh (three иска изрична сфера, иначе
 * frustumCulled=false е единствената безопасна стойност).
 *
 * @param {THREE.InstancedMesh} mesh
 * @param {number} count
 * @param {boolean} castShadow
 */
function finishInstanced(mesh, count, castShadow) {
    mesh.count = count;
    mesh.instanceMatrix.needsUpdate = true;
    if (mesh.instanceColor) {
        mesh.instanceColor.needsUpdate = true;
    }
    mesh.computeBoundingSphere();
    mesh.frustumCulled = true;
    mesh.castShadow = castShadow;
    mesh.receiveShadow = true;
}

/**
 * Бетонен материал: matte (metalness 0), с детайлните карти на десктоп.
 *
 * @param {object} opts
 * @param {{roughness?: number, side?: number}} [extra]
 * @returns {THREE.MeshStandardMaterial}
 */
function concreteMaterial(opts, extra = {}) {
    const material = new THREE.MeshStandardMaterial({
        vertexColors: true,
        metalness: 0,
        roughness: extra.roughness ?? 0.85,
        side: extra.side ?? THREE.DoubleSide,
    });
    if (opts.detail) {
        material.normalMap = opts.detail.normalMap;
        material.normalScale.set(0.35, 0.35);
        material.roughnessMap = opts.detail.roughnessMap;
    }

    return material;
}

// curvatureRanges живее в sim.js — суровина и за декора, и за run-off
// физиката, без three.js по веригата на сървърното повторение.

/**
 * Правите участъци (|кривина| < maxCurv, поне minRows реда), сортирани по
 * дължина. Сканът върви две обиколки, за да хване права през стартовата
 * линия като едно цяло; съдържащите се дублирания се махат.
 *
 * @param {import('./track.js').Track} track
 * @param {number} maxCurv
 * @param {number} minRows
 * @returns {Array<{from: number, len: number}>}
 */
export function straightRuns(track, maxCurv, minRows) {
    const { curvature, count } = track;
    const runs = [];
    let runStart = null;

    for (let i = 0; i < count * 2; i++) {
        const k = Math.abs(curvature[i % count]);
        if (k < maxCurv) {
            if (runStart === null) {
                runStart = i;
            }
        } else if (runStart !== null) {
            if (i - runStart > minRows && runStart < count) {
                runs.push({ from: runStart, len: i - runStart });
            }
            runStart = null;
        }
    }

    runs.sort((a, b) => b.len - a.len);

    const kept = [];
    for (const run of runs) {
        const from = run.from % count;
        const contained = kept.some((k) => {
            const rel = (((from - (k.from % count)) % count) + count) % count;
            return rel + run.len <= k.len;
        });
        if (!contained) {
            kept.push(run);
        }
    }

    return kept;
}

/**
 * Диапазоните за информационни пана: външната страна на всеки завой
 * (от−6..до+6 реда). Без пана по правите — по-малко визуален шум и ясна
 * аварийна зона при висока скорост.
 *
 * @param {import('./track.js').Track} track
 * @returns {Array<{from: number, to: number, side: number}>}
 */
function hoardingSegments(track) {
    const segments = [];
    for (const range of curvatureRanges(track, 0.014, 4)) {
        segments.push({ from: range.from - 6, to: range.to + 6, side: -range.side });
    }

    return segments;
}

/**
 * Спирачните събития: диапазоните на тежките завои, слети през шикан (два
 * съседни диапазона под 12 реда са една спирачна зона). Табели, маршалски
 * постове и мраморчета ги делят.
 *
 * @param {import('./track.js').Track} track
 * @returns {Array<{from: number, to: number, side: number}>}
 */
function brakingEvents(track) {
    const events = [];
    for (const range of curvatureRanges(track, 0.02, 3)) {
        const last = events[events.length - 1];
        if (last && range.from - last.to < 12) {
            last.to = range.to;
        } else {
            events.push({ from: range.from, to: range.to, side: range.side });
        }
    }

    return events;
}

// ── Стартова права: питлейн, решетка, гантри ─────────────────────────────

/**
 * Правият участък около стартовата линия, в редове спрямо ред 0.
 *
 * @param {import('./track.js').Track} track
 * @returns {{back: number, forward: number}} back/forward: брой прави редове
 */
function findStartStraight(track) {
    const { curvature, count } = track;
    const limit = Math.min(180, Math.floor(count / 3));

    let back = 0;
    while (back < limit && Math.abs(curvature[((-back - 1) % count + count) % count]) < 0.006) {
        back++;
    }

    let forward = 0;
    while (forward < limit && Math.abs(curvature[(forward + 1) % count]) < 0.006) {
        forward++;
    }

    return { back, forward };
}

/**
 * Питлейн + пит стена с гантри + гаражна сграда с ВДЛЪБНАТИ боксове покрай
 * стартовата права, от страната на реалните питове (CircuitStyle.pitSide).
 *
 * Целият комплекс стъпва на нивото на питлейна (правило на лентата, не на
 * терена): реалният пит е равен с трасето, а теренът отвъд банкета пада — ако
 * гаражът стъпваше на терена, лентата щеше да виси над прага му.
 *
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @param {{back: number, forward: number}} straight
 * @param {object} opts
 * @returns {{group: THREE.Group, sign: number, from: number, to: number, outerOffset: (row: number) => number, animate: ((dt: number) => void)|null}}
 */
function buildPitComplex(track, circuit, straight, opts) {
    const group = new THREE.Group();
    const sign = circuit.pitSide === 'right' ? 1 : -1;
    const { xs, ys, zs, nx, nz, count, spacing, bankSlope, halfWidths } = track;

    const from = -straight.back + 3;
    const to = straight.forward - 3;
    const span = to - from;

    // Комплексът е прав: ползва най-широката точка в диапазона си (фунията
    // на Монца Т1 попада в края на стартовата права).
    let half = halfWidths[0];
    for (let r = from; r <= to; r++) {
        half = Math.max(half, halfWidths[((r % count) + count) % count]);
    }

    // Няма права — няма питлейн (не би трябвало да се случва на реална писта).
    if (span < 30) {
        const fallback = () => sign * (half + 1.35);
        return { group, sign, from: 0, to: 0, outerOffset: fallback, animate: null };
    }

    const taper = Math.min(14, Math.floor(span * 0.2));
    const laneInner = half + 4.2;
    const laneOuter = half + 10.6;
    const laneDrop = 0.012;

    // Нивото на питлейна: почти равно с трасето, лек спад навън от ръба.
    const laneY = (row, i, offset) =>
        ys[i] - 0.02 - Math.max(0, Math.abs(offset) - halfWidths[i]) * laneDrop - offset * bankSlope[i];

    // Външният ръб на лентата: клин в двата края (вход/изход), пълна ширина
    // по средата. Използва се и от градските стени, за да обиколят питовете.
    const outerOffset = (row) => {
        if (row <= from || row >= to) {
            return sign * laneInner;
        }
        const tIn = (row - from) / taper;
        const tOut = (to - row) / taper;
        const open = Math.min(1, tIn, tOut);
        return sign * (laneInner + (laneOuter - laneInner) * smooth01(open));
    };

    // Асфалтът на питлейна.
    const lane = new THREE.Mesh(
        stripGeometry(track, from, to, () => sign * laneInner, (row) => outerOffset(row), -0.02, {
            color: DECOR.pitLane,
            variation: 0.05,
            drop: laneDrop,
        }),
        new THREE.MeshStandardMaterial({ vertexColors: true, metalness: 0, roughness: 0.92 })
    );
    lane.receiveShadow = true;
    group.add(lane);

    // Пит стената между трасето и лентата — плътна бетонна, с телена ограда
    // отгоре. Стената хвърля сянка (сенчестата камера следва колата).
    const wallFrom = from + taper + 2;
    const wallTo = to - taper - 2;
    const wallOffset = sign * (half + 2.1);
    const wall = new THREE.Mesh(
        wallGeometry(track, wallFrom, wallTo, wallOffset, 1.05, DECOR.pitWallTop, DECOR.pitWallBottom, -0.35, { uvTile: 2 }),
        concreteMaterial(opts, { roughness: 0.7 })
    );
    wall.castShadow = opts.castShadow;
    wall.receiveShadow = true;
    group.add(wall);

    group.add(fenceMesh(track, wallFrom, wallTo, wallOffset, 1.05, 2.6, { maxAniso: opts.maxAniso }));

    // Гантрито на пит стената: стойки на 6 m откъм лентата с монитори, които
    // светят нощем (bloom) и мъждукат денем.
    for (const mesh of buildPitWallGantry(track, wallFrom, wallTo, wallOffset + sign * 0.55, sign, laneY, opts)) {
        group.add(mesh);
    }

    // Бели гранични линии на питлейна: до стената и покрай гаражите.
    const lineOptions = { color: 0xd8d8d8, drop: laneDrop };
    const innerLine = stripGeometry(track, from + taper, to - taper, () => sign * (laneInner + 0.1), () => sign * (laneInner + 0.28), -0.008, lineOptions);
    const outerLine = stripGeometry(track, from + taper, to - taper, () => sign * (laneOuter - 0.28), () => sign * (laneOuter - 0.1), -0.008, lineOptions);
    const lines = mergeGeometries([innerLine, outerLine], false);
    innerLine.dispose();
    outerLine.dispose();
    if (lines) {
        group.add(new THREE.Mesh(lines, new THREE.MeshBasicMaterial({ vertexColors: true })));
    }

    // Линия на ограничението на скоростта (бяла, напречна) на входа.
    group.add(transverseLine(track, (from + taper) * spacing, sign * laneInner, sign * laneOuter, 0.3, 0xf2f2f2, laneY));

    // Гаражната сграда: фасада с отворени боксове + плътно тяло с покрив.
    const garFrom = Math.max(from + taper + 3, Math.floor((from + to) / 2) - 32);
    const garTo = Math.min(to - taper - 3, Math.floor((from + to) / 2) + 32);

    if (garTo - garFrom > 10) {
        const frontOffset = sign * (laneOuter + 1.6);
        const backOffset = sign * (laneOuter + 13);
        const height = 8;
        const spanMeters = (garTo - garFrom) * spacing;
        const bays = Math.floor(spanMeters / 6);

        // Бетонен апрон между лентата и дъното на боксовете (теренът отвъд
        // банкета е по-ниско — без апрона се вижда процеп под гаражите).
        const apron = new THREE.Mesh(
            stripGeometry(track, from + taper, to - taper, () => sign * (laneOuter - 0.05), () => frontOffset + sign * 5.6, -0.03, {
                color: DECOR.pitApron,
                variation: 0.04,
                drop: laneDrop,
            }),
            new THREE.MeshStandardMaterial({ vertexColors: true, metalness: 0, roughness: 0.9 })
        );
        apron.receiveShadow = true;
        group.add(apron);

        // Фасадата: една неповтаряща се текстура с номерирани боксове; вратите
        // са ДУПКИ (alphaTest), зад които стоят инстанцираните черупки.
        const facadeMaps = makeGarageTexture(spanMeters, bays, opts);
        const facadeMaterial = new THREE.MeshStandardMaterial({
            map: facadeMaps.map,
            emissiveMap: facadeMaps.emissiveMap,
            emissive: new THREE.Color(0xffffff),
            emissiveIntensity: opts.night ? 1.8 : 0,
            alphaTest: 0.5,
            metalness: 0.05,
            roughness: 0.7,
            side: THREE.FrontSide,
        });
        const facade = new THREE.Mesh(
            wallGeometry(track, garFrom, garTo, frontOffset, height, 0xffffff, 0xffffff, -0.3, { faceInward: true, groundFn: laneY }),
            facadeMaterial
        );
        facade.castShadow = opts.castShadow;
        facade.receiveShadow = true;
        group.add(facade);

        // Стъклената лента на горния етаж — леко пред фасадата, отразява небето.
        const glass = new THREE.Mesh(
            wallGeometry(track, garFrom, garTo, frontOffset - sign * 0.06, 7.8, 0xffffff, 0xffffff, 5.2, { faceInward: true, groundFn: laneY }),
            new THREE.MeshStandardMaterial({ color: 0x9fb7cc, metalness: 0.9, roughness: 0.12, envMapIntensity: 1.0, side: THREE.FrontSide })
        );
        group.add(glass);

        // Тяло: покрив + задна стена + двата края, слети в една геометрия.
        const roof = stripGeometry(track, garFrom, garTo, () => frontOffset, () => backOffset, height - 0.02, {
            color: DECOR.garageRoof,
            variation: 0.04,
            drop: laneDrop,
        });
        const back = wallGeometry(track, garFrom, garTo, backOffset, height, DECOR.garageBody, DECOR.garageBody, -1.5, { groundFn: laneY });
        const caps = [garFrom, garTo].map((row) => {
            const i = ((row % count) + count) % count;
            const corner = (offset, dy) => [xs[i] + nx[i] * offset, laneY(row, i, offset) + dy, zs[i] + nz[i] * offset];
            return quadGeometry([corner(frontOffset, -1.5), corner(backOffset, -1.5), corner(backOffset, height), corner(frontOffset, height)], DECOR.garageBody);
        });
        const body = mergeGeometries([roof, back, ...caps], false);
        roof.dispose();
        back.dispose();
        for (const cap of caps) {
            cap.dispose();
        }
        if (body) {
            const mesh = new THREE.Mesh(body, new THREE.MeshStandardMaterial({ vertexColors: true, metalness: 0.05, roughness: 0.75, side: THREE.DoubleSide }));
            mesh.castShadow = opts.castShadow;
            mesh.receiveShadow = true;
            group.add(mesh);
        }

        // Боксовете: черупки, стълбове, тавански лампи, стелажи гуми, екипи.
        for (const mesh of buildGarageBays(track, garFrom, bays, frontOffset, sign, laneY, opts)) {
            group.add(mesh);
        }

        // Жълти очертания на пит боксовете: работна линия по дължината и
        // напречни черти между боксовете.
        const outlines = [
            stripGeometry(track, garFrom, garTo, () => frontOffset - sign * 4.3, () => frontOffset - sign * 4.15, -0.006, { color: 0xf5d020, drop: laneDrop }),
        ];
        for (let b = 0; b <= bays; b++) {
            const meters = garFrom * spacing + b * 6;
            outlines.push(transverseLineGeometry(track, meters, frontOffset - sign * 4.3, frontOffset - sign * 0.2, 0.15, 0xf5d020, laneY));
        }
        const outlineGeometry = mergeGeometries(outlines, false);
        for (const g of outlines) {
            g.dispose();
        }
        if (outlineGeometry) {
            group.add(new THREE.Mesh(outlineGeometry, new THREE.MeshBasicMaterial({ vertexColors: true })));
        }
    }

    return { group, sign, from, to, outerOffset, animate: null };
}

/**
 * Напречна боядисана линия на дадени метри между две отмествания.
 *
 * @returns {THREE.Mesh}
 */
function transverseLine(track, meters, offsetA, offsetB, width, color, groundFn) {
    return new THREE.Mesh(
        transverseLineGeometry(track, meters, offsetA, offsetB, width, color, groundFn),
        new THREE.MeshBasicMaterial({ vertexColors: true })
    );
}

/**
 * @returns {THREE.BufferGeometry}
 */
function transverseLineGeometry(track, meters, offsetA, offsetB, width, color, groundFn) {
    const p = pointAt(track, meters);
    const row = Math.round(meters / track.spacing);
    const y = (offset) => groundFn(row, p.i, offset) + 0.012;
    const half = width / 2;
    const corner = (offset, along) => [p.x + p.nx * offset + p.tx * along, y(offset), p.z + p.nz * offset + p.tz * along];
    const lo = Math.min(offsetA, offsetB);
    const hi = Math.max(offsetA, offsetB);

    return quadGeometry([corner(lo, -half), corner(hi, -half), corner(hi, half), corner(lo, half)], color);
}

/**
 * Отворените боксове зад фасадата: инстанцирани черупки (под/таван/три
 * стени), стълбове между тях, светеща тавaнна лента, стелаж гуми във всеки
 * бокс и пит екип (една слята геометрия, един материал) в 30 % от тях.
 *
 * @returns {THREE.Object3D[]}
 */
function buildGarageBays(track, garFrom, bays, frontOffset, sign, laneY, opts) {
    const { spacing, nx, nz } = track;
    const out = [];
    const W = 5.4; // светла ширина между стълбовете
    const D = 5.5; // дълбочина
    const H = 4.4; // височина на вратата

    // Локална рамка на бокса: +x по тангентата (огледално вляво — симетрично),
    // +z навътре в сградата, y нагоре.
    const shells = new THREE.InstancedMesh(
        bayShellGeometry(W, D, H),
        new THREE.MeshStandardMaterial({ vertexColors: true, metalness: 0, roughness: 0.85, side: THREE.DoubleSide }),
        bays
    );

    const pillar = new THREE.BoxGeometry(0.6, H, 0.6);
    pillar.translate(0, H / 2, 0);
    const pillars = new THREE.InstancedMesh(pillar, new THREE.MeshStandardMaterial({ color: 0x9a9ea6, metalness: 0, roughness: 0.8 }), bays + 1);

    const lamp = new THREE.BoxGeometry(W - 0.8, 0.08, 0.3);
    lamp.translate(0, H - 0.06, D / 2);
    const lamps = new THREE.InstancedMesh(
        lamp,
        new THREE.MeshStandardMaterial({ color: 0xfffaf0, emissive: 0xfff1dc, emissiveIntensity: opts.night ? 2.2 : 0.9, roughness: 0.4 }),
        bays
    );

    const rackTyre = new THREE.CylinderGeometry(0.33, 0.33, 0.25, 10);
    const rackParts = [];
    for (let k = 0; k < 4; k++) {
        const tyre = rackTyre.clone();
        tyre.translate(-1.9, 0.13 + k * 0.26, D - 1.2);
        rackParts.push(tyre);
    }
    rackTyre.dispose();
    const rack = mergeGeometries(rackParts, false);
    for (const g of rackParts) {
        g.dispose();
    }
    const racks = new THREE.InstancedMesh(rack, new THREE.MeshStandardMaterial({ color: 0x1c1c1f, metalness: 0, roughness: 0.9 }), bays);

    const matrix = new THREE.Matrix4();
    const position = new THREE.Vector3();
    const quaternion = new THREE.Quaternion();
    const one = new THREE.Vector3(1, 1, 1);
    const suits = [0x2563eb, 0xff7a00, 0x00a36c, 0xd7d7de, 0xe6007e, 0xf5c400, 0xd42a26, 0x8b5cf6];
    const crewParts = [];
    const figure = makeFigureGeometry(0xffffff, 0xe8e8ec, 0x1c1e24);

    const placeAt = (meters) => {
        const p = pointAt(track, meters);
        const row = Math.round(meters / spacing);
        position.set(p.x + p.nx * frontOffset, laneY(row, p.i, frontOffset), p.z + p.nz * frontOffset);
        // Локално +z → sign·n (навътре в сградата).
        quaternion.setFromAxisAngle(UP, Math.atan2(nx[p.i] * sign, nz[p.i] * sign));
    };

    for (let b = 0; b < bays; b++) {
        const centre = garFrom * spacing + (b + 0.5) * 6;
        placeAt(centre);
        matrix.compose(position, quaternion, one);
        shells.setMatrixAt(b, matrix);
        lamps.setMatrixAt(b, matrix);
        racks.setMatrixAt(b, matrix);

        if (hashNoise(b * 3.7 + 11) < 0.3) {
            // Двама механици пред бокса, с лице към лентата.
            for (const dx of [-0.7, 0.7]) {
                const along = centre + dx;
                const q = pointAt(track, along);
                const offset = frontOffset - sign * 1.6;
                const faceYaw = Math.atan2(-q.nx * sign, -q.nz * sign);
                const copy = placedCopy(figure, q.x + q.nx * offset, laneY(Math.round(along / spacing), q.i, offset), q.z + q.nz * offset, faceYaw);
                paintTorso(copy, suits[(b + (dx > 0 ? 1 : 0)) % suits.length]);
                crewParts.push(copy);
            }
        }
    }
    for (let b = 0; b <= bays; b++) {
        placeAt(garFrom * spacing + b * 6);
        matrix.compose(position, quaternion, one);
        pillars.setMatrixAt(b, matrix);
    }
    figure.dispose();

    finishInstanced(shells, bays, false);
    finishInstanced(pillars, bays + 1, opts.castShadow);
    finishInstanced(lamps, bays, false);
    finishInstanced(racks, bays, false);
    out.push(shells, pillars, lamps, racks);

    if (crewParts.length > 0) {
        const crew = mergeGeometries(crewParts, false);
        for (const g of crewParts) {
            g.dispose();
        }
        if (crew) {
            const mesh = new THREE.Mesh(crew, new THREE.MeshStandardMaterial({ vertexColors: true, roughness: 0.8 }));
            mesh.castShadow = opts.castShadow;
            out.push(mesh);
        }
    }

    return out;
}

/**
 * Пребоядисва туловището на поставена в света фигура (върховете между 0.74
 * и 1.33 m над най-ниската ѝ точка) — различни гащеризони от една геометрия.
 *
 * @param {THREE.BufferGeometry} geometry
 * @param {number} color
 */
function paintTorso(geometry, color) {
    const c = new THREE.Color(color);
    const pos = geometry.attributes.position;
    const col = geometry.attributes.color;
    let minY = Infinity;
    for (let v = 0; v < pos.count; v++) {
        minY = Math.min(minY, pos.getY(v));
    }
    for (let v = 0; v < pos.count; v++) {
        const h = pos.getY(v) - minY;
        if (h > 0.74 && h < 1.33) {
            col.setXYZ(v, c.r, c.g, c.b);
        }
    }
}

/**
 * Черупка на бокс: под, таван, задна и две странични стени с vertex цветове,
 * локално x∈[−W/2, W/2], y∈[0, H], z∈[0, D].
 *
 * @returns {THREE.BufferGeometry}
 */
function bayShellGeometry(W, D, H) {
    const parts = [];
    const add = (geometry, color) => {
        paintGeometryFlat(geometry, color);
        parts.push(geometry);
    };

    const floor = new THREE.PlaneGeometry(W, D);
    floor.rotateX(-Math.PI / 2);
    floor.translate(0, 0.01, D / 2);
    add(floor, 0x3b3d42);

    const ceiling = new THREE.PlaneGeometry(W, D);
    ceiling.rotateX(Math.PI / 2);
    ceiling.translate(0, H, D / 2);
    add(ceiling, 0xd8dade);

    const backWall = new THREE.PlaneGeometry(W, H);
    backWall.rotateY(Math.PI);
    backWall.translate(0, H / 2, D);
    add(backWall, 0x6c7078);

    for (const s of [-1, 1]) {
        const side = new THREE.PlaneGeometry(D, H);
        side.rotateY((s * -Math.PI) / 2);
        side.translate((s * W) / 2, H / 2, D / 2);
        add(side, 0x7a7e86);
    }

    const merged = mergeGeometries(parts, false);
    for (const g of parts) {
        g.dispose();
    }

    return merged;
}

/**
 * Гантрито на пит стената: стойки на 6 m + монитори (емисивни: 2.5 нощем,
 * 0.6 денем).
 *
 * @returns {THREE.Object3D[]}
 */
function buildPitWallGantry(track, wallFrom, wallTo, offset, sign, laneY, opts) {
    const { spacing, nx, nz } = track;
    const meters = (wallTo - wallFrom) * spacing;
    const count = Math.max(1, Math.floor(meters / 6));

    const stand = new THREE.BoxGeometry(0.15, 2.4, 0.15);
    stand.translate(0, 1.2, 0);
    const stands = new THREE.InstancedMesh(stand, new THREE.MeshStandardMaterial({ color: 0x2c2f34, metalness: 0.3, roughness: 0.6 }), count);

    const monitor = new THREE.BoxGeometry(1.2, 0.5, 0.08);
    monitor.translate(0, 2.05, 0);
    const monitors = new THREE.InstancedMesh(
        monitor,
        new THREE.MeshStandardMaterial({ color: 0x0c0e12, emissive: 0x9fb4ff, emissiveIntensity: opts.night ? 2.5 : 0.6, roughness: 0.3 }),
        count
    );

    const matrix = new THREE.Matrix4();
    const position = new THREE.Vector3();
    const quaternion = new THREE.Quaternion();
    const one = new THREE.Vector3(1, 1, 1);

    for (let k = 0; k < count; k++) {
        const m = wallFrom * spacing + (k + 0.5) * 6;
        const p = pointAt(track, m);
        position.set(p.x + p.nx * offset, laneY(Math.round(m / spacing), p.i, offset), p.z + p.nz * offset);
        // Мониторите гледат към трасето.
        quaternion.setFromAxisAngle(UP, Math.atan2(-nx[p.i] * sign, -nz[p.i] * sign));
        matrix.compose(position, quaternion, one);
        stands.setMatrixAt(k, matrix);
        monitors.setMatrixAt(k, matrix);
    }
    finishInstanced(stands, count, false);
    finishInstanced(monitors, count, false);

    return [stands, monitors];
}

/**
 * Стартовата решетка: шахматно разположени боксове преди линията — П-образни
 * бели очертания, както на реалната решетка.
 *
 * @param {import('./track.js').Track} track
 * @param {{back: number, forward: number}} straight
 * @returns {THREE.Mesh}
 */
function buildGridSlots(track, straight) {
    const spacingBack = straight.back * track.spacing;
    const slots = Math.max(2, Math.min(20, Math.floor((spacingBack - 14) / 8)));

    const geometries = [];
    const lineW = 0.16;

    for (let j = 0; j < slots; j++) {
        const dist = -(10 + j * 8);
        const lateral = (j % 2 === 0 ? 1 : -1) * Math.min(3.2, track.halfWidths[0] * 0.46);
        const p = pointAt(track, dist);

        // Три ленти: предна черта + две странични назад (П-форма).
        for (const [dx, dz, w, l] of [
            [0, 0, 2.2, lineW], //           предна черта, напречна
            [-1.1 + lineW / 2, -1.4, lineW, 2.8], // лява
            [1.1 - lineW / 2, -1.4, lineW, 2.8], //  дясна
        ]) {
            const cx = p.x + p.nx * (lateral + dx) + p.tx * dz;
            const cz = p.z + p.nz * (lateral + dx) + p.tz * dz;

            const quad = new THREE.PlaneGeometry(w, l);
            quad.rotateX(-Math.PI / 2);
            quad.rotateY(Math.atan2(p.tx, p.tz));
            quad.translate(cx, p.y + Y_GRID, cz);
            geometries.push(quad);
        }
    }

    const merged = mergeGeometries(geometries, false);
    for (const g of geometries) {
        g.dispose();
    }

    return new THREE.Mesh(merged, new THREE.MeshBasicMaterial({ color: 0xe8e8ea, transparent: true, opacity: 0.85 }));
}

/**
 * Гантри над стартовата линия: две колони, решетъчна греда (truss), LED табло
 * и 5×2 лещи — по един емисивен материал НА КОЛОНА, за да може стартовата
 * процедура да ги пали една по една (Game.#launchFrame чете `lights[i]`).
 *
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @param {object} opts
 * @returns {{group: THREE.Group, lights: THREE.MeshStandardMaterial[], setText: (text: string) => void}}
 */
function buildStartGantry(track, circuit, opts) {
    const group = new THREE.Group();
    const half = track.halfWidths[Math.round(6 / track.spacing) % track.count];
    const p = pointAt(track, 6);
    const yaw = Math.atan2(p.tx, p.tz);

    const frame = new THREE.MeshStandardMaterial({ color: DECOR.gantry, metalness: 0.5, roughness: 0.5 });

    const beamY = 7.2;
    const beamLength = half * 2 + 5;
    const section = 1.0; // квадратно сечение на гредата

    const parts = [];
    for (const s of [-1, 1]) {
        const pillar = new THREE.BoxGeometry(0.7, beamY + 1.0, 0.7);
        pillar.translate(s * (half + 2.0), (beamY + 1.0) / 2 - 0.4, 0);
        parts.push(pillar);
    }
    // Четирите пояса на решетката.
    for (const sy of [-1, 1]) {
        for (const sz of [-1, 1]) {
            const chord = new THREE.BoxGeometry(beamLength, 0.12, 0.12);
            chord.translate(0, beamY + (sy * section) / 2, (sz * section) / 2);
            parts.push(chord);
        }
    }
    const merged = mergeGeometries(parts, false);
    for (const g of parts) {
        g.dispose();
    }
    const structure = new THREE.Mesh(merged, frame);
    structure.castShadow = opts.castShadow;
    group.add(structure);

    // Диагоналите на четирите стени на гредата — инстанцирани.
    const bays = Math.max(2, Math.floor(beamLength / section));
    const strut = new THREE.BoxGeometry(0.06, Math.hypot(section, section), 0.06);
    const struts = new THREE.InstancedMesh(strut, frame, bays * 4);
    const matrix = new THREE.Matrix4();
    const position = new THREE.Vector3();
    const quaternion = new THREE.Quaternion();
    const euler = new THREE.Euler();
    const one = new THREE.Vector3(1, 1, 1);
    let n = 0;
    for (let b = 0; b < bays; b++) {
        const x = -beamLength / 2 + (b + 0.5) * section;
        const dir = b % 2 === 0 ? 1 : -1;
        // Вертикални стени (предна/задна): въртене около z; хоризонтални
        // (горна/долна): лежаща диагонала около y.
        for (const sz of [-1, 1]) {
            position.set(x, beamY, (sz * section) / 2);
            quaternion.setFromEuler(euler.set(0, 0, (dir * Math.PI) / 4));
            struts.setMatrixAt(n++, matrix.compose(position, quaternion, one));
        }
        for (const sy of [-1, 1]) {
            position.set(x, beamY + (sy * section) / 2, 0);
            quaternion.setFromEuler(euler.set(0, (dir * Math.PI) / 4, Math.PI / 2));
            struts.setMatrixAt(n++, matrix.compose(position, quaternion, one));
        }
    }
    finishInstanced(struts, n, false);
    group.add(struts);

    // Неутрално LED табло с типа на преживяването и мястото. Леко пред
    // гредата (към прииждащите коли), без марка или търговско послание.
    const board = makeBoardCanvas();
    const boardMaterial = new THREE.MeshStandardMaterial({
        color: 0x000000,
        emissiveMap: board.texture,
        emissive: new THREE.Color(0xffffff),
        emissiveIntensity: 1.4,
        roughness: 0.6,
    });
    const banner = new THREE.Mesh(new THREE.PlaneGeometry(beamLength * 0.7, 0.85), boardMaterial);
    banner.position.set(0, beamY + 1.15, -0.65);
    // Лицето на плоскостта е по +z (надолу по трасето) — обръщаме го към
    // прииждащите коли, иначе четат текста огледално.
    banner.rotation.y = Math.PI;
    group.add(banner);
    board.draw(`ИГРА • ${circuitLabel(circuit, track)}`);

    // Кутията с лещите под гредата: тъмен корпус + 5 колони × 2 лещи.
    const housing = new THREE.Mesh(new THREE.BoxGeometry(5.6, 1.5, 0.5), new THREE.MeshStandardMaterial({ color: 0x15171b, roughness: 0.6 }));
    housing.position.set(0, beamY - 1.3, -0.35);
    group.add(housing);

    const lights = [];
    const lensProto = new THREE.CylinderGeometry(0.22, 0.22, 0.1, 12);
    lensProto.rotateX(Math.PI / 2);
    for (let i = 0; i < 5; i++) {
        const material = new THREE.MeshStandardMaterial({
            color: 0x17090b,
            emissive: 0xff1f1f,
            emissiveIntensity: 0,
            roughness: 0.55,
        });
        const upper = lensProto.clone();
        upper.translate(0, 0.32, 0);
        const lower = lensProto.clone();
        lower.translate(0, -0.32, 0);
        const column = mergeGeometries([upper, lower], false);
        upper.dispose();
        lower.dispose();
        const pod = new THREE.Mesh(column, material);
        pod.position.set((i - 2) * 1.05, beamY - 1.3, -0.64);
        group.add(pod);
        lights.push(material);
    }
    lensProto.dispose();

    group.position.set(p.x, p.y, p.z);
    group.rotation.y = yaw;

    return { group, lights, setText: board.draw };
}

/**
 * Име за таблото: етикетът от визията/конфига, иначе името на трасето.
 *
 * @returns {string}
 */
function circuitLabel(circuit, track) {
    return circuit.nameBg ?? track.name ?? '';
}

/**
 * Canvas на LED таблото 1024×128 с функция за пренаписване (needsUpdate само
 * при setGantryText — Game го вика при нов рекорд, не по кадър).
 *
 * @returns {{texture: THREE.CanvasTexture, draw: (text: string) => void}}
 */
function makeBoardCanvas() {
    const w = 1024;
    const h = 128;
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');
    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.SRGBColorSpace;

    const draw = (text) => {
        ctx.fillStyle = '#0a0a0c';
        ctx.fillRect(0, 0, w, h);
        // LED растер: тъмна решетка върху фона.
        ctx.fillStyle = '#141518';
        for (let x = 0; x < w; x += 8) {
            ctx.fillRect(x, 0, 1, h);
        }
        ctx.fillStyle = '#f2f2f2';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        let size = 64;
        ctx.font = `bold ${size}px sans-serif`;
        const label = String(text ?? '');
        const width = ctx.measureText(label).width || 1;
        if (width > w * 0.9) {
            size = Math.max(24, Math.floor((size * w * 0.9) / width));
            ctx.font = `bold ${size}px sans-serif`;
        }
        ctx.fillText(label, w / 2, h / 2 + 2);
        texture.needsUpdate = true;
    };

    return { texture, draw };
}

// ── Завоите: чакъл, бариери, табели, наденички, мраморчета ───────────────

/**
 * Run-off зони от външната страна на по-бързите завои: чакъл (класика) или
 * асфалтов апрон (модерните писти), според характера на пистата. Пустинните
 * писти (look.terrain.kind 'sand') получават пясъчен тон.
 *
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @param {object} opts
 * @returns {THREE.Mesh|null}
 */
function buildRunoffZones(track, circuit, opts) {
    const halfAt = (i) => track.halfWidths[i];
    const ranges = curvatureRanges(track, 0.014, 4);

    if (ranges.length === 0) {
        return null;
    }

    // Чакълът носи текстура (зрънца/камъчета); vertex цветовете остават
    // почти бели — иначе биха умножили и потъмнили картата.
    const gravelly = circuit.runoff !== 'asphalt';
    const color = gravelly ? 0xffffff : DECOR.asphaltRunoff;
    const variation = gravelly ? 0.08 : 0.05;
    const geometries = [];

    for (const range of ranges) {
        const out = -range.side;
        geometries.push(
            stripGeometry(
                track,
                range.from - 14,
                range.to + 6,
                (row, i) => out * (halfAt(i) + 1.15),
                (row, i) => out * (halfAt(i) + RUNOFF_WIDTH - 0.2),
                Y_GRAVEL,
                { color, variation, drop: RUNOFF_DROP }
            )
        );
    }

    const merged = mergeGeometries(geometries, false);
    for (const g of geometries) {
        g.dispose();
    }

    if (!merged) {
        return null;
    }

    const sandy = opts.look.terrain?.kind === 'sand';
    const mesh = new THREE.Mesh(
        merged,
        gravelly
            ? new THREE.MeshStandardMaterial({ map: makeGravelTexture(sandy), vertexColors: true, metalness: 0, roughness: 1 })
            : new THREE.MeshStandardMaterial({ vertexColors: true, metalness: 0, roughness: 1 })
    );
    mesh.receiveShadow = true;

    return mesh;
}

/**
 * Диапазоните на бариерите (гуми/TecPro) зад run-off зоните на тежките завои.
 *
 * @param {import('./track.js').Track} track
 * @returns {Array<{from: number, to: number, side: number}>}
 */
function barrierRanges(track) {
    return curvatureRanges(track, 0.022, 4);
}

/**
 * Купчини гуми зад run-off зоните: LatheGeometry с четири бандажа (чете се
 * като стек, не като цилиндър), instanceColor, гумена нормала на десктоп;
 * пред тях — лента конвейер, зад тях — защитна ограда.
 *
 * @param {import('./track.js').Track} track
 * @param {object} opts
 * @returns {THREE.Object3D[]}
 */
function buildTyreStacks(track, opts) {
    const { xs, zs, nx, nz, count, halfWidths } = track;
    const ranges = barrierRanges(track);
    const out = [];

    if (ranges.length === 0) {
        return out;
    }

    let capacity = 0;
    for (const range of ranges) {
        capacity += Math.ceil((range.to - range.from + 13) / 3);
    }

    const geometry = tyreStackGeometry();
    const material = new THREE.MeshStandardMaterial({ color: 0xffffff, metalness: 0, roughness: 0.92 });
    if (!opts.lowPower) {
        const rubber = makeNoiseNormalMap({ size: 128, cells: 12, octaves: 2, strength: 0.6, seed: 5 });
        rubber.repeat.set(6, 2);
        material.normalMap = rubber;
        material.normalScale.set(0.5, 0.5);
    }
    const mesh = new THREE.InstancedMesh(geometry, material, capacity);

    const matrix = new THREE.Matrix4();
    const colour = new THREE.Color();
    const base = new THREE.Color(DECOR.tyre);
    let n = 0;
    const belts = [];
    const fences = [];

    for (const range of ranges) {
        const side = -range.side;

        for (let r = range.from - 6; r <= range.to + 6 && n < capacity; r += 3) {
            const i = ((r % count) + count) % count;
            const offset = side * (halfWidths[i] + 8.9);

            matrix.makeRotationY(hashNoise(i) * Math.PI);
            matrix.setPosition(
                xs[i] + nx[i] * offset,
                groundY(track, opts.sampler, i, offset) - 0.05,
                zs[i] + nz[i] * offset
            );
            mesh.setMatrixAt(n, matrix);

            // Предимно черни, с редки бели/червени „маркирани" купчини.
            const roll = hashNoise(i * 3.7);
            colour.set(roll > 0.82 ? DECOR.tyreAccent[roll > 0.92 ? 1 : 0] : base);
            mesh.setColorAt(n, colour);
            n++;
        }

        // Лентата (конвейерът) пред гумите и оградата зад тях — всяка на
        // земята чрез groundFn.
        const groundFn = (row, i, offset) => groundY(track, opts.sampler, i, offset);
        const beltFn = (row, i) => side * (halfWidths[i] + 8.25);
        const fenceFn = (row, i) => side * (halfWidths[i] + 9.7);
        for (const [a, b] of chunkRows(range.from - 6, range.to + 6)) {
            belts.push(wallGeometry(track, a, b, beltFn, 1.1, DECOR.belt, DECOR.belt, -0.15, { uvTile: 2, faceInward: true, groundFn }));
            fences.push(fenceGeometry(track, a, b, fenceFn, -0.3, 3.4, groundFn));
        }
    }

    finishInstanced(mesh, n, opts.castShadow);
    out.push(mesh);

    if (belts.length > 0) {
        out.push(batchChunks(belts, new THREE.MeshStandardMaterial({ vertexColors: true, metalness: 0, roughness: 0.7, side: THREE.FrontSide }), { castShadow: opts.castShadow }));
    }
    if (fences.length > 0) {
        out.push(batchChunks(fences, fenceMaterial(opts), { castShadow: false }));
        out.push(buildFencePoles(track, ranges.map((r) => ({ from: r.from - 6, to: r.to + 6, offsetFn: (i) => -r.side * (halfWidths[i] + 9.7) })), opts));
    }

    return out;
}

/**
 * Профил на стек от четири гуми (издутина на бандаж, стеснение на ръба),
 * потънал 0.12 m под основата.
 *
 * @returns {THREE.LatheGeometry}
 */
function tyreStackGeometry() {
    const points = [new THREE.Vector2(0.0001, -0.12), new THREE.Vector2(0.52, -0.12)];
    for (let k = 0; k < 4; k++) {
        const y0 = k * 0.29;
        points.push(new THREE.Vector2(0.52, y0), new THREE.Vector2(0.6, y0 + 0.07), new THREE.Vector2(0.6, y0 + 0.22), new THREE.Vector2(0.52, y0 + 0.29));
    }
    points.push(new THREE.Vector2(0.0001, 1.16));

    return new THREE.LatheGeometry(points, 12);
}

/**
 * TecPro бариери (модерните асфалтови run-off зони): червено/бели блокове
 * по 2.8 m, плътно един до друг, с ограда зад тях.
 *
 * @param {import('./track.js').Track} track
 * @param {object} opts
 * @returns {THREE.Object3D[]}
 */
function buildTecproBarriers(track, opts) {
    const { spacing, halfWidths } = track;
    const ranges = barrierRanges(track);
    const out = [];

    if (ranges.length === 0) {
        return out;
    }

    const blockLength = 2.8;
    let capacity = 0;
    for (const range of ranges) {
        capacity += Math.ceil(((range.to - range.from + 12) * spacing) / blockLength) + 1;
    }

    // Заоблените ръбове са 108 триъгълника на блок (неиндексирана геометрия)
    // — ~35 k за писта на десктоп; телефонът получава обикновена кутия.
    const geometry = opts.lowPower
        ? new THREE.BoxGeometry(1.0, 1.0, blockLength)
        : new RoundedBoxGeometry(1.0, 1.0, blockLength, 1, 0.08);
    geometry.translate(0, 0.5, 0);
    const mesh = new THREE.InstancedMesh(geometry, new THREE.MeshStandardMaterial({ color: 0xffffff, metalness: 0, roughness: 0.6 }), capacity);

    const matrix = new THREE.Matrix4();
    const position = new THREE.Vector3();
    const quaternion = new THREE.Quaternion();
    const one = new THREE.Vector3(1, 1, 1);
    const colour = new THREE.Color();
    let n = 0;
    const fences = [];

    for (const range of ranges) {
        const side = -range.side;
        const fromM = (range.from - 6) * spacing;
        const toM = (range.to + 6) * spacing;
        for (let m = fromM; m + blockLength <= toM && n < capacity; m += blockLength) {
            const centre = m + blockLength / 2;
            const p = pointAt(track, centre);
            const offset = side * (halfWidths[p.i] + 8.9);
            position.set(p.x + p.nx * offset, groundYAt(track, opts.sampler, centre, offset) - 0.05, p.z + p.nz * offset);
            quaternion.setFromAxisAngle(UP, Math.atan2(p.tx, p.tz));
            mesh.setMatrixAt(n, matrix.compose(position, quaternion, one));
            colour.set(DECOR.tecpro[n % 2]);
            mesh.setColorAt(n, colour);
            n++;
        }

        const groundFn = (row, i, offset) => groundY(track, opts.sampler, i, offset);
        const fenceFn = (row, i) => side * (halfWidths[i] + 9.7);
        for (const [a, b] of chunkRows(range.from - 6, range.to + 6)) {
            fences.push(fenceGeometry(track, a, b, fenceFn, -0.3, 3.4, groundFn));
        }
    }

    finishInstanced(mesh, n, opts.castShadow);
    out.push(mesh);
    if (fences.length > 0) {
        out.push(batchChunks(fences, fenceMaterial(opts), { castShadow: false }));
        out.push(buildFencePoles(track, ranges.map((r) => ({ from: r.from - 6, to: r.to + 6, offsetFn: (i) => -r.side * (halfWidths[i] + 9.7) })), opts));
    }

    return out;
}

/**
 * Стълбовете на оградите: един на ред (4 m) по всеки диапазон, вкопани 0.4 m.
 *
 * @param {import('./track.js').Track} track
 * @param {Array<{from: number, to: number, offsetFn: (i: number) => number}>} ranges
 * @param {object} opts
 * @returns {THREE.InstancedMesh}
 */
function buildFencePoles(track, ranges, opts) {
    const { xs, zs, nx, nz, count } = track;
    let capacity = 0;
    for (const range of ranges) {
        capacity += range.to - range.from + 1;
    }

    const pole = new THREE.BoxGeometry(0.12, 4.0, 0.12);
    pole.translate(0, 1.6, 0);
    const mesh = new THREE.InstancedMesh(pole, new THREE.MeshStandardMaterial({ color: 0x3a3e46, metalness: 0.4, roughness: 0.6 }), Math.max(1, capacity));
    const matrix = new THREE.Matrix4();
    let n = 0;

    for (const range of ranges) {
        for (let r = range.from; r <= range.to && n < capacity; r++) {
            const i = ((r % count) + count) % count;
            const offset = range.offsetFn(i);
            matrix.identity();
            matrix.setPosition(xs[i] + nx[i] * offset, groundY(track, opts.sampler, i, offset), zs[i] + nz[i] * offset);
            mesh.setMatrixAt(n++, matrix);
        }
    }

    finishInstanced(mesh, n, false);

    return mesh;
}

/**
 * Спирачни табели (150/100/50) преди тежките завои. Лице с надпис, гладък
 * гръб (без огледален текст в ТВ
 * кадрите), колчета вкопани в земята.
 *
 * @param {import('./track.js').Track} track
 * @param {object} opts
 * @returns {THREE.Object3D[]}
 */
function buildMarkerBoards(track, opts) {
    const { curvature, count, spacing, halfWidths } = track;

    const placements = { 150: [], 100: [], 50: [] };
    const poles = [];

    for (const event of brakingEvents(track)) {
        const out = -event.side;
        for (const dist of [150, 100, 50]) {
            const row = event.from - Math.round(dist / spacing);
            const i = ((row % count) + count) % count;

            // Табелата стои само на прав участък — вътре в предишния завой не.
            if (Math.abs(curvature[i]) > 0.01) {
                continue;
            }

            placements[dist].push({ i, offset: out * (halfWidths[i] + 2.7) });
            poles.push({ i, offset: out * (halfWidths[i] + 2.7) });
        }
    }

    const meshes = [];
    const boardGeometry = boardQuadGeometry(1.0, 0.78);

    const buildSet = (list, label) => {
        if (list.length === 0) {
            return;
        }
        const texture = makeBoardTexture(label);
        texture.anisotropy = opts.maxAniso;
        const material = new THREE.MeshStandardMaterial({ map: texture, roughness: 0.6, side: THREE.FrontSide });
        const mesh = new THREE.InstancedMesh(boardGeometry, material, list.length);
        const matrix = new THREE.Matrix4();
        const quaternion = new THREE.Quaternion();
        const position = new THREE.Vector3();
        const scale = new THREE.Vector3(1, 1, 1);

        list.forEach((place, n) => {
            const { i, offset } = place;
            // С лице срещу движението — пилотът (и играчът) я четат отдалеч.
            quaternion.setFromAxisAngle(UP, Math.atan2(-track.tx[i], -track.tz[i]));
            position.set(
                track.xs[i] + track.nx[i] * offset,
                groundY(track, opts.sampler, i, offset) + 1.45,
                track.zs[i] + track.nz[i] * offset
            );
            mesh.setMatrixAt(n, matrix.compose(position, quaternion, scale));
        });

        finishInstanced(mesh, list.length, opts.castShadow);
        meshes.push(mesh);
    };

    buildSet(placements[150], '150');
    buildSet(placements[100], '100');
    buildSet(placements[50], '50');

    // Общи колчета под всички табели, вкопани 0.4 m.
    if (poles.length > 0) {
        const poleGeometry = new THREE.BoxGeometry(0.1, 1.5, 0.1);
        poleGeometry.translate(0, 0.35, 0);
        const poleMesh = new THREE.InstancedMesh(
            poleGeometry,
            new THREE.MeshStandardMaterial({ color: 0x2c2f34, roughness: 0.8 }),
            poles.length
        );
        const matrix = new THREE.Matrix4();
        poles.forEach((place, n) => {
            const { i, offset } = place;
            matrix.identity();
            matrix.setPosition(
                track.xs[i] + track.nx[i] * offset,
                groundY(track, opts.sampler, i, offset),
                track.zs[i] + track.nz[i] * offset
            );
            poleMesh.setMatrixAt(n, matrix);
        });
        finishInstanced(poleMesh, poles.length, opts.castShadow);
        meshes.push(poleMesh);
    }

    return meshes;
}

/**
 * Табела: лицев квад (горната половина на текстурата — надписът) + гръб с
 * обърната навивка (долната половина — плътно синьо). Един материал, без
 * огледален текст.
 *
 * @param {number} width
 * @param {number} height
 * @returns {THREE.BufferGeometry}
 */
function boardQuadGeometry(width, height) {
    const front = new THREE.PlaneGeometry(width, height);
    const back = new THREE.PlaneGeometry(width, height);
    back.rotateY(Math.PI);
    back.translate(0, 0, -0.02);
    const remap = (geometry, v0, v1) => {
        const uv = geometry.attributes.uv;
        for (let k = 0; k < uv.count; k++) {
            uv.setY(k, v0 + uv.getY(k) * (v1 - v0));
        }
    };
    remap(front, 0.5, 1);
    remap(back, 0, 0.5);
    const merged = mergeGeometries([front, back], false);
    front.dispose();
    back.dispose();

    return merged;
}

/**
 * Оранжеви „sausage" кербове зад върха на шиканите — подписът на Монца,
 * Силвърстоун и Casio Triangle на Сузука.
 *
 * @param {import('./track.js').Track} track
 * @returns {THREE.InstancedMesh|null}
 */
function buildSausageKerbs(track) {
    const { xs, ys, zs, nx, nz, count, halfWidths, bankSlope } = track;

    // Само много тесните и кратки чупки (шикани), не дългите завои.
    const ranges = curvatureRanges(track, 0.035, 2).filter((r) => r.to - r.from <= 12);

    if (ranges.length === 0) {
        return null;
    }

    const capacity = 90;
    const geometry = new THREE.BoxGeometry(0.5, 0.22, 2.4);
    const material = new THREE.MeshStandardMaterial({ color: DECOR.sausage, metalness: 0.05, roughness: 0.55 });
    const mesh = new THREE.InstancedMesh(geometry, material, capacity);

    const matrix = new THREE.Matrix4();
    const quaternion = new THREE.Quaternion();
    const position = new THREE.Vector3();
    const scale = new THREE.Vector3(1, 1, 1);
    let n = 0;

    for (const range of ranges) {
        for (let r = range.from; r <= range.to && n < capacity; r += 2) {
            const i = ((r % count) + count) % count;
            const offset = range.side * (halfWidths[i] + 1.1 + 0.75); // зад вътрешния керб

            quaternion.setFromAxisAngle(UP, Math.atan2(track.tx[i], track.tz[i]));
            position.set(
                xs[i] + nx[i] * offset,
                ys[i] + 0.11 - offset * bankSlope[i],
                zs[i] + nz[i] * offset
            );
            mesh.setMatrixAt(n, matrix.compose(position, quaternion, scale));
            n++;
        }
    }

    finishInstanced(mesh, n, true);

    return mesh;
}

/**
 * Мраморчетата на изхода на завоите: сплеснати топчета гума по външната
 * страна на ръба (от−8 до +35 реда след завоя). На телефон — наполовина.
 *
 * @param {import('./track.js').Track} track
 * @param {object} opts
 * @returns {THREE.InstancedMesh|null}
 */
function buildMarbles(track, opts) {
    const { xs, ys, zs, nx, nz, count, halfWidths, bankSlope } = track;
    const ranges = curvatureRanges(track, 0.014, 4);
    if (ranges.length === 0) {
        return null;
    }

    const perCorner = opts.lowPower ? 24 : 56;
    const geometry = new THREE.IcosahedronGeometry(0.06, 0);
    geometry.scale(1, 0.5, 1);
    const mesh = new THREE.InstancedMesh(geometry, new THREE.MeshStandardMaterial({ color: 0x141416, metalness: 0, roughness: 0.55 }), ranges.length * perCorner);
    const matrix = new THREE.Matrix4();
    let n = 0;

    for (let c = 0; c < ranges.length; c++) {
        const range = ranges[c];
        const side = -range.side;
        const rows = 43;
        for (let k = 0; k < perCorner; k++) {
            const seed = c * 977 + k * 13;
            const row = range.to - 8 + Math.floor(hashNoise(seed * 1.1) * rows);
            const i = ((row % count) + count) % count;
            const lateral = halfWidths[i] - 1.0 + hashNoise(seed * 2.3) * 2.2;
            const offset = side * lateral;
            const onAsphalt = lateral <= halfWidths[i];
            const y = onAsphalt
                ? ys[i] + 0.02 - offset * bankSlope[i]
                : groundY(track, opts.sampler, i, offset) + 0.02;
            matrix.makeRotationY(hashNoise(seed * 3.7) * Math.PI);
            matrix.setPosition(xs[i] + nx[i] * offset + track.tx[i] * (hashNoise(seed * 5.1) - 0.5) * 3, y, zs[i] + nz[i] * offset + track.tz[i] * (hashNoise(seed * 5.1) - 0.5) * 3);
            mesh.setMatrixAt(n++, matrix);
        }
    }

    finishInstanced(mesh, n, false);

    return mesh;
}

// ── Градски стени ────────────────────────────────────────────────────────

/**
 * Мантинели плътно по двете страни на цялото трасе (градска писта), с
 * предпазна ограда 0.95→3.6 m отгоре и стълбове. Бетон (metalness 0), на
 * парчета по 120 реда в BatchedMesh — един draw за стените, един за
 * оградите, сенчестият pass вижда само парчетата около колата. От страната
 * на питовете стената обикаля питлейна.
 *
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @param {{sign: number, from: number, to: number, outerOffset: (row: number) => number}} pit
 * @param {object} opts
 * @returns {THREE.Object3D[]}
 */
function buildStreetWalls(track, circuit, pit, opts) {
    const baseAt = (i) => track.halfWidths[i] + 1.45;
    const groundFn = (row, i, offset) => groundY(track, opts.sampler, i, offset);

    const nonPitFn = (row, i) => -pit.sign * baseAt(i);
    // Откъм питовете: следва външния ръб на питлейна в неговия диапазон.
    const pitFn = (row, i) => {
        const wrapped = row > track.count / 2 ? row - track.count : row;
        if (wrapped > pit.from && wrapped < pit.to) {
            return pit.outerOffset(wrapped) + pit.sign * 0.9;
        }
        return pit.sign * baseAt(i);
    };

    const walls = [];
    const fences = [];
    for (const offsetFn of [nonPitFn, pitFn]) {
        for (const [a, b] of chunkRows(0, track.count)) {
            walls.push(wallGeometry(track, a, b, offsetFn, 0.95, DECOR.pitWallTop, DECOR.pitWallBottom, -0.35, { uvTile: 2, groundFn }));
            fences.push(fenceGeometry(track, a, b, offsetFn, 0.95, 3.6, groundFn));
        }
    }

    const wallMesh = batchChunks(walls, concreteMaterial(opts, { roughness: 0.8 }), { castShadow: opts.castShadow });
    const fenceBatch = batchChunks(fences, fenceMaterial(opts), { castShadow: false });
    const poles = buildFencePoles(
        track,
        [
            { from: 0, to: track.count - 1, offsetFn: (i) => nonPitFn(i, i) },
            { from: 0, to: track.count - 1, offsetFn: (i) => pitFn(i, i) },
        ],
        opts
    );

    return [wallMesh, fenceBatch, poles];
}

// ── Тунел, маршали, прожектори, хеликоптер ───────────────────────────────

/**
 * Тунелът (Монако): бетонни стени, таван със светеща лента и портални рамки.
 * Таванът хвърля сянка → интериорът реално потъмнява, а лентата е емисивна
 * над прага на bloom-а (1.0) — прочутото оранжево сияние вместо плоска
 * оранжева плоскост.
 *
 * @param {import('./track.js').Track} track
 * @param {{from: number, to: number}} cfg Метри по обиколката
 * @param {object} opts
 * @returns {{group: THREE.Group, rowFrom: number, rowTo: number}}
 */
function buildTunnel(track, cfg, opts) {
    const group = new THREE.Group();
    const rowFrom = Math.round(cfg.from / track.spacing);
    const rowTo = Math.round(cfg.to / track.spacing);

    // Стените стоят на най-широкото място в диапазона — галерията е права.
    let half = 0;
    for (let r = rowFrom; r <= rowTo; r++) {
        half = Math.max(half, track.halfWidths[((r % track.count) + track.count) % track.count]);
    }
    const wallOffset = half + 1.6;
    const height = 4.6;

    const concrete = concreteMaterial(opts, { roughness: 0.85 });

    for (const s of [-1, 1]) {
        const wall = new THREE.Mesh(
            wallGeometry(track, rowFrom, rowTo, s * wallOffset, height, DECOR.concrete, DECOR.concreteLow, -0.35, { uvTile: 2 }),
            concrete
        );
        wall.castShadow = opts.castShadow;
        wall.receiveShadow = true;
        group.add(wall);
    }

    const ceiling = new THREE.Mesh(
        stripGeometry(track, rowFrom, rowTo, -(wallOffset + 0.2), wallOffset + 0.2, height, {
            color: 0x9b968a,
            variation: 0.06,
        }),
        concrete
    );
    ceiling.castShadow = opts.castShadow;
    ceiling.receiveShadow = true;
    group.add(ceiling);

    // Светещата лента по тавана — емисивна ×2.5 → bloom на десктоп, видима
    // и в мъглата на телефон.
    const glow = new THREE.Mesh(
        stripGeometry(track, rowFrom + 1, rowTo - 1, -0.4, 0.4, height - 0.08, { color: 0xffffff }),
        new THREE.MeshStandardMaterial({ color: 0x1a1208, emissive: 0xffb45e, emissiveIntensity: 2.5, roughness: 0.5, side: THREE.DoubleSide })
    );
    group.add(glow);

    // Портални рамки на двата края.
    const headerMaterial = new THREE.MeshStandardMaterial({ color: 0x7d786d, roughness: 0.8 });
    for (const row of [rowFrom, rowTo]) {
        const i = ((row % track.count) + track.count) % track.count;
        const header = new THREE.Mesh(new THREE.BoxGeometry(wallOffset * 2 + 1.6, 1.4, 1.0), headerMaterial);
        header.position.set(track.xs[i], track.ys[i] + height + 0.5, track.zs[i]);
        header.rotation.y = Math.atan2(track.tx[i], track.tz[i]);
        header.castShadow = opts.castShadow;
        group.add(header);
    }

    return { group, rowFrom, rowTo };
}

/**
 * Маршалски постове на входа на тежките завои: фигура в оранжев елек (всички
 * фигури в ЕДИН меш), прибран жълт флаг на прът (един меш на пост, за да се
 * върти pivot-ът) и LED панел, чийто материал Game пали (intensity 3) при
 * връщане на пистата.
 *
 * @param {import('./track.js').Track} track
 * @param {object} sampler
 * @returns {{group: THREE.Group, posts: Array<{index: number, pivot: THREE.Group, panel: THREE.MeshStandardMaterial}>}}
 */
function buildMarshalPosts(track, sampler) {
    const group = new THREE.Group();
    const posts = [];

    const figure = makeFigureGeometry(0xff7a1a);
    const figures = [];

    // Прът + плат в една геометрия с vertex цветове; вълната тежи 0 при
    // пръта (x≈0), така че прътът стои неподвижен.
    const pole = new THREE.CylinderGeometry(0.018, 0.018, 0.8, 5);
    pole.translate(0, 0.3, 0);
    paintGeometryFlat(pole, 0x2c2f34);
    const cloth = clothGeometry(0.6, 0.4);
    cloth.translate(0, 0.55, 0);
    paintGeometryFlat(cloth, 0xf5d020);
    const poleFlat = pole.toNonIndexed();
    pole.dispose();
    const clothFlat = cloth.toNonIndexed();
    cloth.dispose();
    const flagGeometry = mergeGeometries([poleFlat, clothFlat], false);
    poleFlat.dispose();
    clothFlat.dispose();
    const flagMaterial = makeFlagMaterial(null, { vertexColors: true, width: 0.6 });

    const panelGeometry = new THREE.PlaneGeometry(0.5, 0.35);

    for (const event of brakingEvents(track)) {
        const row = event.from - Math.round(18 / track.spacing);
        const i = ((row % track.count) + track.count) % track.count;
        const offset = -event.side * (track.halfWidths[i] + 2.4);
        const yaw = Math.atan2(-track.nx[i] * Math.sign(offset), -track.nz[i] * Math.sign(offset));

        const post = new THREE.Group();
        post.position.set(
            track.xs[i] + track.nx[i] * offset,
            groundY(track, sampler, i, offset),
            track.zs[i] + track.nz[i] * offset
        );
        post.rotation.y = yaw;

        figures.push(placedCopy(figure, post.position.x, post.position.y, post.position.z, yaw));

        // Флагът виси на пръта; pivot-ът при дланта се върти при „веене".
        const pivot = new THREE.Group();
        pivot.position.set(0.3, 1.35, 0);
        pivot.add(new THREE.Mesh(flagGeometry, flagMaterial));
        // В покой флагът е спуснат (прибран до тялото).
        pivot.rotation.z = 1.25;
        post.add(pivot);

        // LED панелът на поста — тъмен, докато Game не го запали.
        const panel = new THREE.MeshStandardMaterial({ color: 0x15171b, emissive: 0xf5d020, emissiveIntensity: 0, roughness: 0.5 });
        const panelMesh = new THREE.Mesh(panelGeometry, panel);
        panelMesh.position.set(-0.55, 1.7, 0);
        post.add(panelMesh);

        group.add(post);
        posts.push({ index: i, pivot, panel });
    }

    figure.dispose();
    if (figures.length > 0) {
        const merged = mergeGeometries(figures, false);
        for (const g of figures) {
            g.dispose();
        }
        if (merged) {
            group.add(new THREE.Mesh(merged, new THREE.MeshStandardMaterial({ vertexColors: true, roughness: 0.8 })));
        }
    }

    return { group, posts };
}

/**
 * Прожекторните кули на нощните писти: пилони на ~120 m с греещи глави.
 * Самата светлина е сборният directional в Game — кулите са визията ѝ;
 * на десктоп bloom-ът (threshold 1.0) подпалва главите. Позициите на главите
 * се връщат за ореолите/конусите (nightLights).
 *
 * @param {import('./track.js').Track} track
 * @param {{from: number, to: number, sign: number}} pit Диапазонът на пит комплекса
 * @param {boolean} hasGrandstands Стартови трибуни (mesh.js) на -pit.sign страната
 * @param {object} opts
 * @returns {{group: THREE.Group, towers: Array<{x: number, y: number, z: number, dirX: number, dirZ: number, index: number, side: number}>}}
 */
function buildFloodlightTowers(track, pit, hasGrandstands, opts) {
    const group = new THREE.Group();
    const every = Math.max(1, Math.round(120 / track.spacing));
    const count = Math.ceil(track.count / every);
    const towers = [];

    // Пилонът продължава 0.4 m под основата — теренът не е равен.
    const pole = new THREE.CylinderGeometry(0.22, 0.3, 14.4, 6);
    pole.translate(0, 6.8, 0);
    const poles = new THREE.InstancedMesh(
        pole,
        new THREE.MeshStandardMaterial({ color: 0x3a3e46, metalness: 0.4, roughness: 0.6 }),
        count
    );

    const head = new THREE.BoxGeometry(1.9, 0.55, 0.3);
    head.translate(0, 14.1, 0);
    const heads = new THREE.InstancedMesh(
        head,
        new THREE.MeshStandardMaterial({
            color: 0x1a1c20,
            emissive: 0xf2f6ff,
            emissiveIntensity: 3.2,
            roughness: 0.4,
        }),
        count
    );

    const matrix = new THREE.Matrix4();
    const position = new THREE.Vector3();
    const quaternion = new THREE.Quaternion();
    const scale = new THREE.Vector3(1, 1, 1);

    // Пит зоната в увити редове (както buildDistanceMarkers в mesh.js) —
    // кула на half+11 би стояла точно между питлейна и фасадата на гаражите.
    const inPit = (i) => {
        const wrapped = i > track.count / 2 ? i - track.count : i;
        return wrapped > pit.from && wrapped < pit.to;
    };

    // Стартовите трибуни заемат -pit.sign страната ~20 m преди до ~150 m
    // след линията (mesh.js buildStartGrandstands) — пилон там пронизва
    // седалките и публиката.
    const inGrandstand = (i) => {
        const wrapped = i > track.count / 2 ? i - track.count : i;
        const meters = wrapped * track.spacing;
        return meters > -20 && meters < 150;
    };

    const blocked = (i, side) =>
        (inPit(i) && side === pit.sign) ||
        (hasGrandstands && side === -pit.sign && inGrandstand(i));

    let placed = 0;
    let slot = 0;
    for (let i = 0; i < track.count && placed < count; i += every) {
        // Редуваме страните по слота (не по placed — пропуснат ред да не
        // разбърква редуването); заета страна → отсрещната, двете заети
        // (стартовата права: пит + трибуна) → без кула на този ред.
        let side = slot % 2 === 0 ? 1 : -1;
        slot++;
        if (blocked(i, side)) {
            side = -side;
            if (blocked(i, side)) {
                continue;
            }
        }
        const offset = side * (track.halfWidths[i] + 11);
        const dirX = -track.nx[i] * side;
        const dirZ = -track.nz[i] * side;

        position.set(
            track.xs[i] + track.nx[i] * offset,
            groundY(track, opts.sampler, i, offset),
            track.zs[i] + track.nz[i] * offset
        );
        // Главата гледа към трасето.
        quaternion.setFromAxisAngle(UP, Math.atan2(dirX, dirZ));
        matrix.compose(position, quaternion, scale);
        poles.setMatrixAt(placed, matrix);
        heads.setMatrixAt(placed, matrix);
        towers.push({ x: position.x, y: position.y + 14.1, z: position.z, dirX, dirZ, index: i, side });
        placed++;
    }

    finishInstanced(poles, placed, false);
    finishInstanced(heads, placed, false);
    group.add(poles);
    group.add(heads);

    return { group, towers };
}

/**
 * ТВ хеликоптерът: low-poly силует, кръжащ бавно над трасето с размазан
 * ротор, мигаща навигационна светлина и нощем прожектор към колата
 * (getCarPosition от Game). Орбитата е над най-високата точка на терена.
 *
 * @param {import('./track.js').Track} track
 * @param {object} sampler
 * @param {object} opts
 * @returns {{group: THREE.Group, animate: (dt: number) => void}}
 */
function buildHelicopter(track, sampler, opts) {
    // Центроид на трасето — орбитата е около него.
    let cx = 0;
    let cz = 0;
    let top = -Infinity;
    let minX = Infinity;
    let maxX = -Infinity;
    let minZ = Infinity;
    let maxZ = -Infinity;
    for (let i = 0; i < track.count; i++) {
        cx += track.xs[i];
        cz += track.zs[i];
        top = Math.max(top, track.ys[i]);
        minX = Math.min(minX, track.xs[i]);
        maxX = Math.max(maxX, track.xs[i]);
        minZ = Math.min(minZ, track.zs[i]);
        maxZ = Math.max(maxZ, track.zs[i]);
    }
    cx /= track.count;
    cz /= track.count;

    // Най-високият терен в кутията на трасето + 300 m (хълмовете на Спа).
    for (let iz = 0; iz <= 12; iz++) {
        for (let ix = 0; ix <= 12; ix++) {
            const x = minX - 300 + ((maxX - minX + 600) * ix) / 12;
            const z = minZ - 300 + ((maxZ - minZ + 600) * iz) / 12;
            top = Math.max(top, sampleHeight(sampler, x, z));
        }
    }

    const group = new THREE.Group();
    const body = new THREE.Group();

    const dark = new THREE.MeshStandardMaterial({ color: 0x23262e, metalness: 0.3, roughness: 0.55 });
    const accent = new THREE.MeshStandardMaterial({ color: 0xd7d9de, metalness: 0.2, roughness: 0.5 });

    const hull = new THREE.Mesh(new THREE.SphereGeometry(1.6, 8, 6), dark);
    hull.scale.set(1, 0.72, 1.5);
    body.add(hull);

    const tail = new THREE.Mesh(new THREE.CylinderGeometry(0.16, 0.34, 5.2, 6), accent);
    tail.rotation.x = Math.PI / 2;
    tail.position.set(0, 0.25, -3.8);
    body.add(tail);

    // Роторът: перка + полупрозрачен диск (размазването на 40 rad/s).
    const rotor = new THREE.Mesh(new THREE.BoxGeometry(9, 0.06, 0.35), dark);
    rotor.position.y = 1.35;
    body.add(rotor);
    const disc = new THREE.Mesh(
        new THREE.CircleGeometry(4.5, 24),
        new THREE.MeshBasicMaterial({ color: 0x23262e, transparent: true, opacity: 0.3, depthWrite: false, side: THREE.DoubleSide })
    );
    disc.rotation.x = -Math.PI / 2;
    disc.position.y = 1.34;
    body.add(disc);

    // Мигаща червена навигационна светлина на опашката.
    const navMaterial = new THREE.MeshStandardMaterial({ color: 0x220000, emissive: 0xff2020, emissiveIntensity: 0, roughness: 0.4 });
    const nav = new THREE.Mesh(new THREE.SphereGeometry(0.16, 6, 5), navMaterial);
    nav.position.set(0, 0.9, -6.2);
    body.add(nav);

    group.add(body);

    // Нощем: прожектор — конус от корема към колата (адитивен, без дълбочина).
    let cone = null;
    if (opts.night && opts.getCarPosition) {
        const coneGeometry = new THREE.ConeGeometry(14, 1, 16, 1, true);
        coneGeometry.translate(0, -0.5, 0);
        coneGeometry.rotateX(-Math.PI / 2); // върхът към −z → lookAt насочва оста
        cone = new THREE.Mesh(
            coneGeometry,
            new THREE.MeshBasicMaterial({ color: 0xffe9c8, transparent: true, opacity: 0.06, depthWrite: false, blending: THREE.AdditiveBlending, side: THREE.DoubleSide, fog: false })
        );
        cone.frustumCulled = false;
        group.add(cone);
    }

    // Орбита: радиус до най-далечната точка + отстъп, височина ~90 m над
    // най-високото.
    let radius = 0;
    for (let i = 0; i < track.count; i += 8) {
        radius = Math.max(radius, Math.hypot(track.xs[i] - cx, track.zs[i] - cz));
    }
    radius = radius * 0.55;
    const height = top + 90;

    let angle = 0;
    let blink = 0;
    const target = new THREE.Vector3();
    const animate = (dt) => {
        angle += dt * 0.03; // ~3.5 минути на обиколка на орбитата
        const x = cx + Math.cos(angle) * radius;
        const z = cz + Math.sin(angle) * radius;
        group.position.set(x, height, z);
        // Носът по посоката на движение + лек крен навътре. Орбитата
        // (cos a, sin a) има курс -a в three.js yaw — без допълнителни 90°,
        // иначе носът сочи радиално към центъра и машината лети странично.
        group.rotation.y = -angle;
        group.rotation.z = 0.12;
        rotor.rotation.y += dt * 40;

        blink += dt;
        if (blink > 1) {
            blink -= 1;
        }
        navMaterial.emissiveIntensity = blink < 0.12 ? 4 : 0;

        if (cone) {
            const car = opts.getCarPosition();
            if (car) {
                target.copy(car);
                // Конусът е дете на групата: lookAt на Object3D работи в
                // световни координати, а мащабът по z е разстоянието.
                cone.lookAt(target);
                cone.scale.z = Math.max(1, group.position.distanceTo(target));
            }
        }
    };
    animate(0);

    return { group, animate };
}

// ── Мостове ──────────────────────────────────────────────────────────────

/**
 * Пешеходен мост над най-дългата права — пасарелките с реклами са част от
 * силуета на всяка постоянна писта. Прескача правите, чиято среда попада в
 * пит зоната (на Монца и Зандвоорт най-дългата права Е стартовата — кулата
 * на моста иначе стъпва в питлейна).
 *
 * @param {import('./track.js').Track} track
 * @param {{from: number, to: number}} pit Диапазонът на пит комплекса в редове
 * @param {object} opts
 * @returns {THREE.Mesh|null}
 */
function buildFootbridge(track, pit, opts) {
    const { count } = track;

    let i = null;
    for (const run of straightRuns(track, 0.004, 90)) {
        const row = (run.from + Math.round(run.len * 0.55)) % count;
        const wrapped = row > count / 2 ? row - count : row;
        if (wrapped <= pit.from - 8 || wrapped >= pit.to + 8) {
            i = row;
            break;
        }
    }

    if (i === null) {
        return null;
    }

    const half = track.halfWidths[i];
    const span = 2 * (half + 6);
    const deckY = 6.2;
    const parts = [];

    for (const s of [-1, 1]) {
        // Кулите стъпват на земята (groundY) и продължават 1.2 m под нея.
        const offset = s * (half + 5);
        const base = groundY(track, opts.sampler, i, offset) - track.ys[i];
        const tower = new THREE.BoxGeometry(1.4, deckY + 1.2 - base, 1.4);
        tower.translate(offset, (deckY + base) / 2 - 0.6, 0);
        paintGeometryFlat(tower, 0x7d838c);
        parts.push(tower);
    }

    const deck = new THREE.BoxGeometry(span, 0.9, 2.6);
    deck.translate(0, deckY, 0);
    paintGeometryFlat(deck, 0x656b74);
    parts.push(deck);

    for (const s of [-1, 1]) {
        const rail = new THREE.BoxGeometry(span, 1.0, 0.14);
        rail.translate(0, deckY + 0.9, s * 1.2);
        paintGeometryFlat(rail, 0x8b9199);
        parts.push(rail);
    }

    const geometry = mergeGeometries(parts, false);
    for (const part of parts) {
        part.dispose();
    }

    if (!geometry) {
        return null;
    }

    const mesh = new THREE.Mesh(
        geometry,
        new THREE.MeshStandardMaterial({ vertexColors: true, metalness: 0, roughness: 0.7 })
    );
    mesh.position.set(track.xs[i], track.ys[i], track.zs[i]);
    mesh.rotation.y = Math.atan2(track.tx[i], track.tz[i]);
    mesh.castShadow = opts.castShadow;
    mesh.receiveShadow = true;

    return mesh;
}

/**
 * Грейд-сепарации, детектирани от самопресичането на трасето в план — на
 * практика мостът на осмицата на Сузука. Строи греда под горното платно и
 * два пилона край долното; връща и редовете (аудио slapback под моста).
 *
 * @param {import('./track.js').Track} track
 * @param {object} opts
 * @returns {{meshes: THREE.Object3D[], crossings: Array<{index: number, lower: number, x: number, z: number}>}}
 */
function buildCrossoverBridges(track, opts) {
    const { xs, ys, zs, count, halfWidths } = track;
    const meshes = [];
    const found = [];

    // Пресичане на отсечки в план, с изискване за реална денивелация. Двете
    // обиколки на j гарантират и двойките през стартовата линия.
    for (let i = 0; i < count; i++) {
        const ax = xs[i];
        const az = zs[i];
        const bx = xs[(i + 1) % count];
        const bz = zs[(i + 1) % count];

        for (let j = i + 40; j < count; j++) {
            // Съседни сегменти (по индекс, с wrap) не са пресичане.
            if (count - (j - i) < 40) {
                continue;
            }

            const cx = xs[j];
            const cz = zs[j];

            // Бърз reject по разстояние — истинско пресичане иска близост.
            if ((ax - cx) * (ax - cx) + (az - cz) * (az - cz) > 400) {
                continue;
            }

            const dx = xs[(j + 1) % count];
            const dz = zs[(j + 1) % count];

            const d1 = cross2(bx - ax, bz - az, cx - ax, cz - az);
            const d2 = cross2(bx - ax, bz - az, dx - ax, dz - az);
            const d3 = cross2(dx - cx, dz - cz, ax - cx, az - cz);
            const d4 = cross2(dx - cx, dz - cz, bx - cx, bz - cz);

            if (d1 * d2 < 0 && d3 * d4 < 0 && Math.abs(ys[i] - ys[j]) > 3) {
                // Дедупликация: съседните двойки сегменти намират същия възел.
                const near = found.some((f) => (f.x - ax) * (f.x - ax) + (f.z - az) * (f.z - az) < 900);
                if (!near) {
                    found.push({ x: ax, z: az, lower: ys[i] < ys[j] ? i : j, upper: ys[i] < ys[j] ? j : i });
                }
            }
        }
    }

    const material = new THREE.MeshStandardMaterial({ vertexColors: true, metalness: 0, roughness: 0.75 });

    for (const crossing of found) {
        const up = crossing.upper;
        const low = crossing.lower;
        const upHalf = halfWidths[up];
        const gap = ys[up] - ys[low];
        const parts = [];

        // Греда под горното платно, по неговата посока.
        const girder = new THREE.BoxGeometry(upHalf * 2 + 3.5, 1.3, 26);
        girder.translate(0, -0.75, 0);
        paintGeometryFlat(girder, 0x5c6168);
        parts.push(girder);

        // Странични фасции — четат се като мост от долния път.
        for (const s of [-1, 1]) {
            const fascia = new THREE.BoxGeometry(0.3, 1.7, 26);
            fascia.translate(s * (upHalf + 1.9), -0.2, 0);
            paintGeometryFlat(fascia, 0x9aa0a8);
            parts.push(fascia);
        }

        const geometry = mergeGeometries(parts, false);
        for (const part of parts) {
            part.dispose();
        }

        if (!geometry) {
            continue;
        }

        const bridge = new THREE.Mesh(geometry, material);
        bridge.position.set(xs[up], ys[up], zs[up]);
        bridge.rotation.y = Math.atan2(track.tx[up], track.tz[up]);
        bridge.castShadow = opts.castShadow;
        bridge.receiveShadow = true;
        meshes.push(bridge);

        // Пилони край долното платно, до опорите на гредата.
        const pillarGeo = [];
        for (const s of [-1, 1]) {
            const pillar = new THREE.BoxGeometry(1.3, gap - 1.6, 1.3);
            pillar.translate(s * (halfWidths[low] + 2.6), (gap - 1.6) / 2, 0);
            paintGeometryFlat(pillar, 0x6e747c);
            pillarGeo.push(pillar);
        }
        const pillars = mergeGeometries(pillarGeo, false);
        for (const part of pillarGeo) {
            part.dispose();
        }
        if (pillars) {
            const mesh = new THREE.Mesh(pillars, material);
            mesh.position.set(xs[low], ys[low], zs[low]);
            mesh.rotation.y = Math.atan2(track.tx[low], track.tz[low]);
            mesh.receiveShadow = true;
            meshes.push(mesh);
        }
    }

    return {
        meshes,
        crossings: found.map((f) => ({ index: f.upper, lower: f.lower, x: f.x, z: f.z })),
    };
}

/** z-компонент на 2D векторно произведение. */
function cross2(ax, az, bx, bz) {
    return ax * bz - az * bx;
}

// ── Ориентири ────────────────────────────────────────────────────────────

/**
 * Локалната рамка на пристанището: център, ориентация по трасето и превръщане
 * на световни координати в локални (u напречно, v по протежение). Същата
 * рамка ползва и terrain.js за снишаването на терена под водата.
 *
 * @param {import('./track.js').Track} track
 * @param {{along: number, side: number, dist: number, width: number, depth: number, waterY: number}} cfg
 */
function harborFrame(track, cfg) {
    const p = pointAt(track, cfg.along);
    const cx = p.x + p.nx * cfg.side * cfg.dist;
    const cz = p.z + p.nz * cfg.side * cfg.dist;

    return {
        cx,
        cz,
        halfW: cfg.width / 2,
        halfD: cfg.depth / 2,
        waterY: cfg.waterY,
        yaw: Math.atan2(p.tx, p.tz),
        toLocal(x, z) {
            const dx = x - cx;
            const dz = z - cz;
            return {
                u: dx * p.nx + dz * p.nz,
                v: dx * p.tx + dz * p.tz,
            };
        },
    };
}

/**
 * Пристанището на Монако: вода с движещи се вълни и бели яхти. Десктоп —
 * Water (планарно отражение, 512² RT) с процедурна нормална карта, пропускано
 * когато камерата е далече (>450 m) или гледа настрани; телефон — обикновена
 * плоскост със същата анимирана нормала, без втори pass.
 *
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @param {object} opts
 * @returns {{group: THREE.Group, animate: (dt: number) => void, disposables: Array<{dispose: () => void}>}}
 */
function buildHarbor(track, circuit, opts) {
    const cfg = circuit.landmark;
    const frame = harborFrame(track, cfg);
    const group = new THREE.Group();
    const disposables = [];

    // Ширината ляга по нормалата на трасето, дълбочината — по тангентата
    // (същата рамка като harborFrame.toLocal, за да съвпадне с терена).
    const waterGeometry = new THREE.PlaneGeometry(cfg.width, cfg.depth);
    waterGeometry.rotateX(-Math.PI / 2);

    const normals = makeWaterNormals();
    const sunDir = sunDirectionOf(circuit);
    let water;
    let tick;

    if (!opts.lowPower) {
        water = new Water(waterGeometry, {
            textureWidth: 512,
            textureHeight: 512,
            waterNormals: normals,
            sunDirection: sunDir,
            sunColor: circuit.atmosphere?.sunColor ?? 0xffffff,
            waterColor: DECOR.water,
            distortionScale: 2.5,
            // Мъглата на атмосферата е кръпка по материалите на three; чуждият
            // ShaderMaterial на Water стои извън нея.
            fog: false,
        });
        water.material.uniforms.size.value = 6;

        // Огледалният pass е пълен втори рендер на сцената: само когато
        // пристанището е близо и в полезрението.
        const inner = water.onBeforeRender;
        const toWater = new THREE.Vector3();
        const forward = new THREE.Vector3();
        water.onBeforeRender = (renderer, scene, camera) => {
            toWater.set(frame.cx, cfg.waterY, frame.cz).sub(camera.position);
            const dist = toWater.length();
            camera.getWorldDirection(forward);
            if (dist > 450 || toWater.divideScalar(Math.max(dist, 1e-3)).dot(forward) < -0.2) {
                return;
            }
            inner(renderer, scene, camera);
        };

        const target = water.material.uniforms.mirrorSampler.value.renderTarget;
        disposables.push({
            dispose() {
                target?.dispose();
                normals.dispose();
            },
        });
        tick = (dt) => {
            water.material.uniforms.time.value += dt * 0.6;
        };
    } else {
        normals.repeat.set(8, 10);
        water = new THREE.Mesh(
            waterGeometry,
            new THREE.MeshStandardMaterial({ color: DECOR.water, metalness: 0.4, roughness: 0.15, normalMap: normals, normalScale: new THREE.Vector2(0.35, 0.35) })
        );
        tick = (dt) => {
            normals.offset.x = (normals.offset.x + dt * 0.02) % 1;
            normals.offset.y = (normals.offset.y + dt * 0.013) % 1;
        };
    }
    water.rotation.y = frame.yaw;
    water.position.set(frame.cx, cfg.waterY, frame.cz);
    group.add(water);

    // Яхтите: корпус + кабина в една геометрия, инстанцирани из залива.
    const hull = new THREE.BoxGeometry(3.2, 1.4, 11);
    hull.translate(0, 0.7, 0);
    const cabin = new THREE.BoxGeometry(2.2, 1.3, 4.2);
    cabin.translate(0, 2.0, -0.8);
    const yachtGeometry = mergeGeometries([hull, cabin], false);
    hull.dispose();
    cabin.dispose();

    const capacity = 14;
    const yachts = new THREE.InstancedMesh(
        yachtGeometry,
        new THREE.MeshStandardMaterial({ color: DECOR.yacht, metalness: 0.2, roughness: 0.4 }),
        capacity
    );

    const matrix = new THREE.Matrix4();
    const quaternion = new THREE.Quaternion();
    const position = new THREE.Vector3();
    const scale = new THREE.Vector3();
    const p = pointAt(track, cfg.along);

    for (let n = 0; n < capacity; n++) {
        // u по нормалата (ширината на залива), v по тангентата (дълбочината).
        const u = (hashNoise(n * 3.1) - 0.5) * (cfg.width - 40);
        const v = (hashNoise(n * 7.7) - 0.5) * (cfg.depth - 40);
        const s = 0.7 + hashNoise(n * 5.3) * 0.8;

        position.set(
            frame.cx + p.nx * u + p.tx * v,
            cfg.waterY,
            frame.cz + p.nz * u + p.tz * v
        );
        quaternion.setFromAxisAngle(UP, hashNoise(n * 11.3) * Math.PI * 2);
        scale.set(s, s, s);
        yachts.setMatrixAt(n, matrix.compose(position, quaternion, scale));
    }

    finishInstanced(yachts, capacity, false);
    group.add(yachts);

    return { group, animate: tick, disposables };
}

/**
 * Посоката към слънцето по конвенцията на Game.#setupEnvironment
 * (setFromSphericalCoords(1, 90°−elevation, azimuth)).
 *
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @returns {THREE.Vector3}
 */
function sunDirectionOf(circuit) {
    const atmosphere = circuit.atmosphere ?? {};
    const phi = THREE.MathUtils.degToRad(90 - (atmosphere.sunElevation ?? 45));
    const theta = THREE.MathUtils.degToRad(atmosphere.sunAzimuth ?? 140);

    return new THREE.Vector3().setFromSphericalCoords(1, phi, theta);
}

/**
 * Безшевна нормална карта на вода: шест сумирани синусоиди с цели периоди
 * по плочката (256²), без DOM.
 *
 * @returns {THREE.DataTexture}
 */
function makeWaterNormals() {
    const size = 256;
    const waves = [
        [3, 1, 0.9], [1, 4, 0.7], [5, 3, 0.5], [2, -5, 0.45], [7, 2, 0.3], [-4, 6, 0.25],
    ];
    const height = new Float32Array(size * size);
    for (let y = 0; y < size; y++) {
        for (let x = 0; x < size; x++) {
            let h = 0;
            for (const [kx, ky, amp] of waves) {
                h += Math.sin(((kx * x + ky * y) / size) * Math.PI * 2) * amp;
            }
            height[y * size + x] = h * 0.5 + 0.5;
        }
    }

    const data = new Uint8Array(size * size * 4);
    for (let y = 0; y < size; y++) {
        for (let x = 0; x < size; x++) {
            const l = height[y * size + ((x + size - 1) % size)];
            const r = height[y * size + ((x + 1) % size)];
            const d = height[((y + size - 1) % size) * size + x];
            const u = height[((y + 1) % size) * size + x];
            const nx = (l - r) * 6;
            const ny = (d - u) * 6;
            const len = Math.hypot(nx, ny, 1);
            const o = (y * size + x) * 4;
            data[o] = Math.round((nx / len * 0.5 + 0.5) * 255);
            data[o + 1] = Math.round((ny / len * 0.5 + 0.5) * 255);
            data[o + 2] = Math.round((1 / len * 0.5 + 0.5) * 255);
            data[o + 3] = 255;
        }
    }

    const texture = new THREE.DataTexture(data, size, size, THREE.RGBAFormat);
    texture.wrapS = THREE.RepeatWrapping;
    texture.wrapT = THREE.RepeatWrapping;
    texture.magFilter = THREE.LinearFilter;
    texture.minFilter = THREE.LinearMipmapLinearFilter;
    texture.generateMipmaps = true;
    texture.needsUpdate = true;

    return texture;
}

/**
 * Ред развети знамена срещу питовете, около старт/финала: пилоните в един
 * меш, всяко платно със свой материал (вълна по върховете + лек люлеж на
 * пилона). Български трикольори между пъстрите.
 *
 * @param {import('./track.js').Track} track
 * @param {{sign: number}} pit
 * @param {object} sampler
 * @returns {{group: THREE.Group, animate: (dt: number) => void}}
 */
function buildStartFlags(track, pit, sampler) {
    const group = new THREE.Group();
    const side = -pit.sign;
    const offset = side * (track.halfWidths[0] + 3.4);

    const poleProto = new THREE.CylinderGeometry(0.05, 0.05, 5.6, 6);
    poleProto.translate(0, 2.6, 0);
    const poles = [];

    const bg = makeTricolourTexture();
    const palette = [0xd42a26, 0xe6e6e6, 0x2470b8, 0xe0a83a];
    const flags = [];

    for (let f = 0; f < 7; f++) {
        const m = -36 + f * 14;
        const p = pointAt(track, m);
        const x = p.x + p.nx * offset;
        const z = p.z + p.nz * offset;
        const y = groundYAt(track, sampler, m, offset);
        const yaw = Math.atan2(p.tx, p.tz);

        poles.push(placedCopy(poleProto, x, y, z, yaw));

        const material = makeFlagMaterial(f % 3 === 0 ? bg : palette[f % palette.length], { phase: f * 1.7 });
        const flag = new THREE.Mesh(clothGeometry(1.3, 0.8), material);
        flag.position.set(x, y + 4.6, z);
        flag.rotation.y = yaw;
        group.add(flag);
        flags.push({ mesh: flag, yaw });
    }
    poleProto.dispose();

    const poleGeometry = mergeGeometries(poles, false);
    for (const g of poles) {
        g.dispose();
    }
    if (poleGeometry) {
        group.add(new THREE.Mesh(poleGeometry, new THREE.MeshStandardMaterial({ color: 0x9aa0a8, metalness: 0.6, roughness: 0.4 })));
    }

    let t = 0;
    const animate = (dt) => {
        t += dt;
        for (let k = 0; k < flags.length; k++) {
            flags[k].mesh.rotation.y = flags[k].yaw + Math.sin(t * 1.3 + k * 1.7) * 0.25;
        }
    };

    return { group, animate };
}

/**
 * Български трикольор — canvas, 2:1.
 *
 * @returns {THREE.CanvasTexture}
 */
function makeTricolourTexture() {
    const canvas = document.createElement('canvas');
    canvas.width = 96;
    canvas.height = 64;
    const ctx = canvas.getContext('2d');

    ctx.fillStyle = '#f4f4f4';
    ctx.fillRect(0, 0, 96, 22);
    ctx.fillStyle = '#00966e';
    ctx.fillRect(0, 22, 96, 21);
    ctx.fillStyle = '#d62612';
    ctx.fillRect(0, 43, 96, 21);

    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.SRGBColorSpace;

    return texture;
}

/**
 * Виенското колело на Мотопия зад стартовата права на Сузука — бяла стомана,
 * цветни кабинки, бавно въртене. Кабинките висят изправени, докато колелото
 * се върти (иначе на 26 m радиус наклонът им се чете).
 *
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @param {object} sampler
 * @returns {{group: THREE.Group, animate: (dt: number) => void}}
 */
function buildFerrisWheel(track, circuit, sampler) {
    const cfg = circuit.landmark;
    const p = pointAt(track, cfg.along);
    const cx = p.x + p.nx * cfg.side * cfg.dist;
    const cz = p.z + p.nz * cfg.side * cfg.dist;
    const baseY = sampleHeight(sampler, cx, cz);

    const radius = 26;
    const hubY = radius + 6;

    const group = new THREE.Group();
    group.position.set(cx, baseY, cz);
    // С лице към трасето.
    group.rotation.y = Math.atan2(p.x - cx, p.z - cz);

    const steel = new THREE.MeshStandardMaterial({ color: DECOR.wheelSteel, metalness: 0.55, roughness: 0.4 });

    // Носеща конструкция: две А-рамки към главината.
    const legs = [];
    for (const s of [-1, 1]) {
        for (const lean of [-0.28, 0.28]) {
            const leg = new THREE.BoxGeometry(0.9, hubY * 1.06, 0.9);
            leg.rotateX(lean);
            leg.translate(s * 3.4, hubY / 2, lean * -6);
            legs.push(leg);
        }
    }
    const hub = new THREE.CylinderGeometry(1.4, 1.4, 8.4, 10);
    hub.rotateZ(Math.PI / 2);
    hub.translate(0, hubY, 0);
    legs.push(hub);

    const legGeometry = mergeGeometries(legs, false);
    for (const g of legs) {
        g.dispose();
    }
    group.add(new THREE.Mesh(legGeometry, steel));

    // Въртящата се част: обръч + спици.
    const spinning = new THREE.Group();
    spinning.position.y = hubY;
    group.add(spinning);

    const rim = new THREE.TorusGeometry(radius, 0.9, 8, 44);
    const parts = [rim];
    for (let s = 0; s < 8; s++) {
        const spoke = new THREE.BoxGeometry(0.5, radius * 2, 0.5);
        spoke.rotateZ((s * Math.PI) / 8);
        parts.push(spoke);
    }
    const wheelGeometry = mergeGeometries(parts, false);
    for (const g of parts) {
        g.dispose();
    }
    spinning.add(new THREE.Mesh(wheelGeometry, steel));

    // Кабинките: в родителя (не във въртящата се група), преизчисляват се на
    // кадър, за да останат изправени. Движат се → без сфера/culling.
    const gondolaCount = 14;
    const gondolas = new THREE.InstancedMesh(
        new THREE.BoxGeometry(2.4, 2.6, 1.8),
        new THREE.MeshStandardMaterial({ color: 0xffffff, metalness: 0.1, roughness: 0.6 }),
        gondolaCount
    );
    const palette = [0xd94f4f, 0x4f7fd9, 0xe0c24f, 0x5ad07a, 0xc94fd9, 0xe8834e, 0x58c8d4];
    const colour = new THREE.Color();
    for (let n = 0; n < gondolaCount; n++) {
        colour.set(palette[n % palette.length]);
        gondolas.setColorAt(n, colour);
    }
    if (gondolas.instanceColor) {
        gondolas.instanceColor.needsUpdate = true;
    }
    gondolas.frustumCulled = false;
    group.add(gondolas);

    let angle = 0;
    const matrix = new THREE.Matrix4();

    const placeGondolas = () => {
        for (let n = 0; n < gondolaCount; n++) {
            const a = angle + (n * Math.PI * 2) / gondolaCount;
            matrix.identity();
            matrix.setPosition(Math.cos(a) * radius, hubY + Math.sin(a) * radius - 1.6, 0);
            gondolas.setMatrixAt(n, matrix);
        }
        gondolas.instanceMatrix.needsUpdate = true;
    };
    placeGondolas();

    const animate = (dt) => {
        angle += dt * 0.06;
        spinning.rotation.z = angle;
        placeGondolas();
    };

    return { group, animate };
}

/**
 * Границите на трибуните — стартовите (същите константи като mesh.js
 * buildStartGrandstands) и OSM контурите — за ТВ постовете и светкавиците
 * на публиката. Радиус вместо hx/hz: секциите са завъртени по трасето.
 *
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @param {{sign: number}} pit
 * @param {object} sampler
 * @returns {Array<{cx: number, cz: number, radius: number, top: number, index: number}>}
 */
function computeGrandstandBounds(track, circuit, pit, sampler) {
    const bounds = [];
    const { xs, ys, zs, nx, nz, count, spacing } = track;

    if (circuit.startGrandstands) {
        const GAP = 7;
        const DEPTH = 18;
        const HEIGHT = 12;
        const SECTION = 26;
        const SPAN = 165;
        const sections = Math.max(3, Math.round(SPAN / SECTION));
        const step = Math.max(1, Math.round(SECTION / spacing));
        const startBack = Math.round(20 / spacing);
        const sign = -pit.sign;
        const off = sign * (track.halfWidths[0] + GAP + DEPTH / 2);
        for (let s = 0; s < sections; s++) {
            const i = (((s * step - startBack) % count) + count) % count;
            bounds.push({
                cx: xs[i] + nx[i] * off,
                cz: zs[i] + nz[i] * off,
                radius: Math.hypot(DEPTH, SECTION) / 2,
                top: ys[i] + HEIGHT + 0.4,
                index: i,
            });
        }
    }

    for (const ring of track.landmarks?.grandstands ?? []) {
        if (ring.length < 3) {
            continue;
        }
        let cx = 0;
        let cz = 0;
        for (const [x, z] of ring) {
            cx += x;
            cz += z;
        }
        cx /= ring.length;
        cz /= ring.length;
        let radius = 0;
        for (const [x, z] of ring) {
            radius = Math.max(radius, Math.hypot(x - cx, z - cz));
        }
        bounds.push({ cx, cz, radius, top: sampleHeight(sampler, cx, cz) + 11, index: nearestRow(track, cx, cz) });
    }

    return bounds;
}

/**
 * Най-близкият ред на трасето до точка (стъпка 4 — достатъчно за индекс).
 */
function nearestRow(track, x, z) {
    let best = 0;
    let bestDist = Infinity;
    for (let i = 0; i < track.count; i += 4) {
        const d = (track.xs[i] - x) ** 2 + (track.zs[i] - z) ** 2;
        if (d < bestDist) {
            bestDist = d;
            best = i;
        }
    }

    return best;
}

// ── Текстури (canvas, детерминирани) ─────────────────────────────────────

/**
 * Фасада на пит гаражите: ЕДНА неповтаряща се текстура за целия span —
 * номерирани боксове по 6 m, цветна лента на „отбора", стъклен горен етаж.
 * Вратите са прозрачни (alphaTest в материала) — зад тях стоят 3D черупките.
 * Емисивната карта: номерата (60 % топли) и прозорците (40 % студени).
 *
 * @param {number} spanMeters
 * @param {number} bays
 * @param {object} opts
 * @returns {{map: THREE.CanvasTexture, emissiveMap: THREE.CanvasTexture}}
 */
function makeGarageTexture(spanMeters, bays, opts) {
    const w = Math.max(1024, Math.min(4096, Math.round(spanMeters * 24)));
    const h = 512;
    const ppm = w / spanMeters; // пиксели на метър по дължина
    const ppmY = h / 8; //        по височина (фасадата е 8 m)
    const yOf = (metres) => h - metres * ppmY; // v=0 е долу (flipY)

    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');
    const glow = document.createElement('canvas');
    glow.width = w;
    glow.height = h;
    const gctx = glow.getContext('2d');

    // Тяло: бетон с леки хоризонтални фуги.
    ctx.fillStyle = '#b0b3ba';
    ctx.fillRect(0, 0, w, h);
    ctx.fillStyle = 'rgba(0,0,0,0.08)';
    for (const m of [4.4, 5.2, 7.8]) {
        ctx.fillRect(0, yOf(m) - 2, w, 3);
    }
    // Парапет на покрива.
    ctx.fillStyle = '#8f9299';
    ctx.fillRect(0, 0, w, yOf(7.8));

    gctx.fillStyle = '#000000';
    gctx.fillRect(0, 0, w, h);

    // Стъклен етаж 5.2–7.8 m с вертикални профили на 1.5 m.
    const gradient = ctx.createLinearGradient(0, yOf(7.8), 0, yOf(5.2));
    gradient.addColorStop(0, '#a9bccc');
    gradient.addColorStop(1, '#6f8599');
    ctx.fillStyle = gradient;
    ctx.fillRect(0, yOf(7.8), w, yOf(5.2) - yOf(7.8));
    ctx.fillStyle = '#4a515c';
    const cells = Math.floor(spanMeters / 1.5);
    for (let c = 0; c <= cells; c++) {
        ctx.fillRect(c * 1.5 * ppm - 1, yOf(7.8), 3, yOf(5.2) - yOf(7.8));
        if (c < cells && hashNoise(c * 4.1 + 2) < 0.4) {
            gctx.fillStyle = '#a8c4ff';
            gctx.globalAlpha = 0.5 + hashNoise(c * 6.3) * 0.5;
            gctx.fillRect(c * 1.5 * ppm + 3, yOf(7.7), 1.5 * ppm - 6, yOf(5.3) - yOf(7.7));
            gctx.globalAlpha = 1;
        }
    }

    const teams = ['#c0392b', '#2980b9', '#16a085', '#e67e22', '#8e44ad', '#2c3e50', '#f1c40f', '#27ae60', '#d35400', '#7f8c8d', '#e84393', '#0984e3'];
    for (let b = 0; b < bays; b++) {
        const x0 = b * 6 * ppm;
        // Стълбовете (0.3 m от всяка страна на бокса) остават бетон; вратата
        // 0.3..5.7 × 0..4.4 m е дупка.
        ctx.clearRect(x0 + 0.3 * ppm, yOf(4.4), 5.4 * ppm, h - yOf(4.4));
        // Горен ръб на вратата (сянка на лintel-а).
        ctx.fillStyle = 'rgba(0,0,0,0.25)';
        ctx.fillRect(x0 + 0.3 * ppm, yOf(4.4) - 4, 5.4 * ppm, 4);
        // Лента на отбора 4.5–5.1 m.
        ctx.fillStyle = teams[Math.floor(hashNoise(b * 13.7 + 5) * teams.length)];
        ctx.fillRect(x0 + 0.3 * ppm, yOf(5.1), 5.4 * ppm, yOf(4.5) - yOf(5.1));
        // Номер на бокса: тъмна плочка с бяла цифра над вратата вляво.
        const plateW = 0.9 * ppm;
        const plateH = yOf(4.45) - yOf(5.15);
        ctx.fillStyle = '#1b1d22';
        ctx.fillRect(x0 + 0.45 * ppm, yOf(5.15), plateW, plateH);
        ctx.fillStyle = '#ffffff';
        ctx.font = `bold ${Math.floor(plateH * 0.7)}px sans-serif`;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(String(b + 1), x0 + 0.45 * ppm + plateW / 2, yOf(5.15) + plateH / 2 + 1);
        if (hashNoise(b * 2.9 + 1) < 0.6) {
            gctx.fillStyle = '#ffe9c8';
            gctx.fillRect(x0 + 0.45 * ppm, yOf(5.15), plateW, plateH);
        }
    }

    const map = new THREE.CanvasTexture(canvas);
    map.colorSpace = THREE.SRGBColorSpace;
    map.wrapS = THREE.ClampToEdgeWrapping;
    map.wrapT = THREE.ClampToEdgeWrapping;
    map.anisotropy = opts.maxAniso;

    const emissiveMap = new THREE.CanvasTexture(glow);
    emissiveMap.colorSpace = THREE.SRGBColorSpace;
    emissiveMap.wrapS = THREE.ClampToEdgeWrapping;
    emissiveMap.wrapT = THREE.ClampToEdgeWrapping;

    return { map, emissiveMap };
}

/**
 * Текстура на чакъл: пясъчна основа с хиляди зрънца и по-едри камъчета;
 * пустинният вариант е по-светъл и жълт.
 *
 * @param {boolean} sandy
 * @returns {THREE.CanvasTexture}
 */
function makeGravelTexture(sandy) {
    const size = 256;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');

    ctx.fillStyle = sandy ? '#e6d6b2' : '#b9a878';
    ctx.fillRect(0, 0, size, size);

    for (let i = 0; i < 3200; i++) {
        const shade = hashNoise(i * 1.91);
        ctx.fillStyle = shade > 0.5
            ? `rgba(255, 246, 224, ${0.25 + shade * 0.3})`
            : `rgba(74, 64, 46, ${0.2 + shade * 0.4})`;
        ctx.fillRect(hashNoise(i * 2.71) * size, hashNoise(i * 3.37) * size, 2, 2);
    }

    for (let i = 0; i < 240; i++) {
        ctx.fillStyle = hashNoise(i * 5.3) > 0.5 ? (sandy ? '#c9b78e' : '#a08e64') : (sandy ? '#f3e7c8' : '#cdbd92');
        ctx.beginPath();
        ctx.arc(hashNoise(i * 7.7) * size, hashNoise(i * 9.1) * size, 1.5 + hashNoise(i * 11.3) * 1.6, 0, Math.PI * 2);
        ctx.fill();
    }

    const texture = new THREE.CanvasTexture(canvas);
    texture.wrapS = THREE.RepeatWrapping;
    texture.wrapT = THREE.RepeatWrapping;
    texture.colorSpace = THREE.SRGBColorSpace;
    // UV са метрични (4 m): една плочка чакъл на 2 m.
    texture.repeat.set(2, 2);

    return texture;
}

/**
 * Табела с число (150/100/50): горната половина — бяло върху синьо
 * с бяла рамка (лицето), долната — плътно синьо (гърбът).
 *
 * @param {string} label
 * @returns {THREE.CanvasTexture}
 */
function makeBoardTexture(label) {
    const w = 256;
    const h = 400;
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');

    ctx.fillStyle = '#1c2f6e';
    ctx.fillRect(0, 0, w, h);
    ctx.strokeStyle = '#f2f2f2';
    ctx.lineWidth = 10;
    ctx.strokeRect(8, 8, w - 16, h / 2 - 16);

    ctx.fillStyle = '#ffffff';
    ctx.font = 'bold 96px sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(label, w / 2, h / 4 + 4);

    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.SRGBColorSpace;

    return texture;
}

// ── Дребни числови помощници ─────────────────────────────────────────────

const UP = new THREE.Vector3(0, 1, 0);

/**
 * Детерминиран шум в [0,1) от число (същият като в mesh.js).
 *
 * @param {number} n
 * @returns {number}
 */
function hashNoise(n) {
    const x = Math.sin(n * 12.9898) * 43758.5453;

    return x - Math.floor(x);
}

/** Плавна S-крива върху [0,1]. */
function smooth01(v) {
    const t = clamp01(v);

    return t * t * (3 - 2 * t);
}

/**
 * @param {number} v
 * @returns {number}
 */
function clamp01(v) {
    return v < 0 ? 0 : v > 1 ? 1 : v;
}
