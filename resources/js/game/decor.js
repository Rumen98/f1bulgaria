/**
 * Декорът, който превръща „трасе с правилната форма" в разпознаваема писта:
 * питлейн комплекс, стартова решетка, гантри със светлини, спирачни табели,
 * чакълени зони, купчини гуми, мантинели (градски писти), гумираната идеална
 * линия, терен с релеф около трасето и специалните ориентири (виенското колело
 * на Сузука, пристанището на Монако).
 *
 * Всичко е процедурно от осевата линия + CircuitStyle (circuits.js). Никакви
 * външни модели; геометрията се слива/инстанцира — бюджетът е ~20 draw call-а
 * общо за целия модул, телефон-безопасно.
 */

import * as THREE from 'three';
import { mergeGeometries } from 'three/examples/jsm/utils/BufferGeometryUtils.js';

// Синхронизирани с mesh.js — теренът и банкетите трябва да се снаждат.
const RUNOFF_DROP = 0.035;
const RUNOFF_WIDTH = 8;

const DECOR = {
    gravel: 0xb9a878,
    asphaltRunoff: 0x46464c,
    pitLane: 0x3d3d44,
    pitWallTop: 0xd9dde2,
    pitWallBottom: 0x8f959c,
    garageBody: 0xb6b9bf,
    garageRoof: 0x585d66,
    gantry: 0x33373d,
    boardBlue: 0x1c2f6e,
    sausage: 0xe07b1a,
    tyre: 0x1c1c1f,
    tyreAccent: [0xd0d0d4, 0xc23a32],
    wheelSteel: 0xe8eaee,
    water: 0x14507a,
    yacht: 0xf2f3f5,
};

/** Височинно наслояване (виж Y в mesh.js) — под ръбовите линии (0.012). */
const Y_RACING_LINE = 0.006;
const Y_GRID = 0.013;
const Y_GRAVEL = -0.1;

/**
 * Сглобява целия декор за пистата.
 *
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @param {TerrainSampler & object} sampler Общият терен (createTerrainSampler)
 * @returns {{group: THREE.Group, startLights: THREE.MeshStandardMaterial|null, animations: Array<(dt: number) => void>}}
 */
export function buildCircuitDecor(track, circuit, sampler) {
    const group = new THREE.Group();
    const animations = [];

    const straight = findStartStraight(track);
    const pit = buildPitComplex(track, circuit, straight);
    group.add(pit.group);

    // Материалът на чакъла се подменя от Game.#loadTrackTextures с истинска
    // PBR текстура (public/game-textures/gravel), ако се зареди навреме.
    let gravelMaterial = null;

    group.add(buildGridSlots(track, straight));

    const gantry = buildStartGantry(track);
    group.add(gantry.group);

    const racingLine = computeRacingLine(track);
    group.add(buildRacingLine(track, racingLine));

    const brakeMarks = buildBrakeMarks(track, racingLine);
    if (brakeMarks) {
        group.add(brakeMarks);
    }

    // Мостове: пасарелка над най-дългата права (без градските писти) и
    // автоматично детектираните грейд-сепарации (осмицата на Сузука).
    if (!circuit.streetWalls) {
        const footbridge = buildFootbridge(track, pit);
        if (footbridge) {
            group.add(footbridge);
        }
    }
    for (const bridge of buildCrossoverBridges(track)) {
        group.add(bridge);
    }

    if (circuit.runoff !== 'none') {
        const gravel = buildRunoffZones(track, circuit);
        if (gravel) {
            group.add(gravel);
            if (circuit.runoff !== 'asphalt') {
                gravelMaterial = gravel.material;
            }
        }

        const tyres = buildTyreStacks(track);
        if (tyres) {
            group.add(tyres);
        }
    }

    const boards = buildMarkerBoards(track, pit.sign);
    for (const mesh of boards) {
        group.add(mesh);
    }

    if (circuit.sausageKerbs) {
        const sausages = buildSausageKerbs(track);
        if (sausages) {
            group.add(sausages);
        }
    }

    if (circuit.streetWalls) {
        group.add(buildStreetWalls(track, circuit, pit));
    }

    group.add(buildTerrain(track, circuit, sampler));

    if (circuit.landmark?.type === 'ferris_wheel') {
        const wheel = buildFerrisWheel(track, circuit, sampler);
        group.add(wheel.group);
        animations.push(wheel.animate);
    }

    if (circuit.landmark?.type === 'harbor') {
        group.add(buildHarbor(track, circuit));
    }

    // Развети знамена край стартовата зона — животът в кадъра, който продава
    // сцената. Български трикольори между пъстрите: публиката ни е нашата.
    if (circuit.startGrandstands || circuit.streetWalls) {
        const flags = buildStartFlags(track, pit);
        group.add(flags.group);
        animations.push(flags.animate);
    }

    return {
        group,
        startLights: gantry.lights,
        animations,
        gravelMaterial,
        // Диапазонът на пит комплекса (редове спрямо ред 0 + страна) — mesh.js
        // го ползва, за да не слага стълбчета/трибуни върху питлейна.
        pitRange: { from: pit.from, to: pit.to, sign: pit.sign },
    };
}

/**
 * Диапазоните на run-off зоните (същите като buildRunoffZones строи) — Game
 * ги ползва, за да знае дали колата е в ЧАКЪЛА (тежко дърпане), или в тревата.
 *
 * @param {import('./track.js').Track} track
 * @returns {Array<{from: number, to: number, side: number}>} side: страната на
 *          завоя; зоната е от СРЕЩУПОЛОЖНАТА (-side) страна на трасето
 */
export function runoffRanges(track) {
    return curvatureRanges(track, 0.014, 4).map((r) => ({
        from: r.from - 14,
        to: r.to + 6,
        side: r.side,
    }));
}

// ── Геометрични помощници ────────────────────────────────────────────────

/**
 * Точка на `meters` метра по обиколката (интерполирана между осевите точки),
 * с локалната рамка тангента/нормала.
 *
 * @param {import('./track.js').Track} track
 * @param {number} meters Може да е отрицателно (преди старта)
 * @returns {{x: number, y: number, z: number, tx: number, tz: number, nx: number, nz: number}}
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
    };
}

/**
 * Лента между две странични отмествания за диапазон от редове (виж ribbonMesh
 * в mesh.js — това е обобщението ѝ: частичен диапазон и отмествания-функции).
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
function stripGeometry(track, rowFrom, rowTo, offsetA, offsetB, y, options) {
    const { xs, ys, zs, nx, nz, count, spacing, curvature } = track;

    const rows = rowTo - rowFrom + 1;
    const positions = new Float32Array(rows * 2 * 3);
    const colors = new Float32Array(rows * 2 * 3);
    const uvs = new Float32Array(rows * 2 * 2);
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
        // от радиуса, се сгъва навътре отвъд центъра на завоя.
        const k = curvature[i];
        const innerLimit = k !== 0 ? 0.5 / k : 0;

        for (let side = 0; side < 2; side++) {
            const fn = side === 0 ? offsetA : offsetB;
            let offset = evalOffset(fn, row, i);
            if (k > 0) {
                offset = Math.min(offset, innerLimit);
            } else if (k < 0) {
                offset = Math.max(offset, innerLimit);
            }

            const vi = (r * 2 + side) * 3;
            positions[vi] = xs[i] + nx[i] * offset;
            positions[vi + 1] = ys[i] + y - Math.abs(offset) * drop;
            positions[vi + 2] = zs[i] + nz[i] * offset;

            const uvi = (r * 2 + side) * 2;
            uvs[uvi] = side;
            uvs[uvi + 1] = (r * spacing) / 8;

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

    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    geometry.setAttribute('uv', new THREE.BufferAttribute(uvs, 2));
    geometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));
    geometry.setIndex(new THREE.BufferAttribute(indices, 1));
    geometry.computeVertexNormals();
    geometry.computeBoundingSphere();

    return geometry;
}

/**
 * Вертикална стена по трасето: лента от долен до горен ръб, двустранна.
 *
 * @param {import('./track.js').Track} track
 * @param {number} rowFrom
 * @param {number} rowTo
 * @param {number|((row: number, i: number) => number)} offsetFn
 * @param {number} height Горен ръб, метри над платното
 * @param {number} colorTop
 * @param {number} colorBottom
 * @param {number} bottom Долен ръб (по подразбиране леко вкопан)
 * @returns {THREE.BufferGeometry}
 */
function wallGeometry(track, rowFrom, rowTo, offsetFn, height, colorTop, colorBottom, bottom = -0.35) {
    const { xs, ys, zs, nx, nz, count } = track;

    const rows = rowTo - rowFrom + 1;
    const positions = new Float32Array(rows * 2 * 3);
    const colors = new Float32Array(rows * 2 * 3);
    // UV: u по дължината (0..1 по цялата лента, мащабира се с texture.repeat),
    // v: 0 долу / 1 горе. Нужно и за текстурираните фасади, и за да са
    // атрибутите съвместими със stripGeometry при mergeGeometries.
    const uvs = new Float32Array(rows * 2 * 2);
    const indices = new Uint32Array((rows - 1) * 6);

    const top = new THREE.Color(colorTop);
    const low = new THREE.Color(colorBottom);

    for (let r = 0; r < rows; r++) {
        const row = rowFrom + r;
        const i = ((row % count) + count) % count;
        const offset = typeof offsetFn === 'function' ? offsetFn(row, i) : offsetFn;

        const bx = xs[i] + nx[i] * offset;
        const bz = zs[i] + nz[i] * offset;

        // Долният ръб е леко вкопан (по подразбиране) — теренът не е равен.
        const vi = r * 6;
        positions[vi] = bx;
        positions[vi + 1] = ys[i] + bottom;
        positions[vi + 2] = bz;
        positions[vi + 3] = bx;
        positions[vi + 4] = ys[i] + height;
        positions[vi + 5] = bz;

        colors[vi] = low.r;
        colors[vi + 1] = low.g;
        colors[vi + 2] = low.b;
        colors[vi + 3] = top.r;
        colors[vi + 4] = top.g;
        colors[vi + 5] = top.b;

        const uvi = r * 4;
        uvs[uvi] = r / (rows - 1);
        uvs[uvi + 1] = 0;
        uvs[uvi + 2] = r / (rows - 1);
        uvs[uvi + 3] = 1;
    }

    for (let r = 0; r < rows - 1; r++) {
        const a = r * 2;
        const t = r * 6;
        indices[t] = a;
        indices[t + 1] = a + 2;
        indices[t + 2] = a + 1;
        indices[t + 3] = a + 1;
        indices[t + 4] = a + 2;
        indices[t + 5] = a + 3;
    }

    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    geometry.setAttribute('uv', new THREE.BufferAttribute(uvs, 2));
    geometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));
    geometry.setIndex(new THREE.BufferAttribute(indices, 1));
    geometry.computeVertexNormals();
    geometry.computeBoundingSphere();

    return geometry;
}

/**
 * Диапазони, в които |кривината| надхвърля праг — суровината за чакъл, табели
 * и купчини гуми. Не слива диапазони с различен знак (шикан = два диапазона).
 *
 * @param {import('./track.js').Track} track
 * @param {number} minCurv
 * @param {number} minLen Минимална дължина в редове
 * @returns {Array<{from: number, to: number, side: number, peak: number}>}
 */
function curvatureRanges(track, minCurv, minLen) {
    const { curvature, count } = track;
    const ranges = [];
    let current = null;

    for (let i = 0; i < count; i++) {
        const k = curvature[i];
        const side = k > minCurv ? 1 : k < -minCurv ? -1 : 0;

        if (side === 0) {
            if (current) {
                ranges.push(current);
                current = null;
            }
            continue;
        }

        if (current && current.side === side) {
            current.to = i;
            current.peak = Math.max(current.peak, Math.abs(k));
        } else {
            if (current) {
                ranges.push(current);
            }
            current = { from: i, to: i, side, peak: Math.abs(k) };
        }
    }

    if (current) {
        ranges.push(current);
    }

    return ranges.filter((r) => r.to - r.from >= minLen);
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
 * Питлейн + пит стена + гаражна сграда покрай стартовата права, от страната
 * на реалните питове (CircuitStyle.pitSide, проверена срещу реалните писти).
 *
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @param {{back: number, forward: number}} straight
 * @returns {{group: THREE.Group, sign: number, from: number, to: number, outerOffset: (row: number) => number}}
 */
function buildPitComplex(track, circuit, straight) {
    const group = new THREE.Group();
    const half = track.width / 2;
    const sign = circuit.pitSide === 'right' ? 1 : -1;

    const from = -straight.back + 3;
    const to = straight.forward - 3;
    const span = to - from;

    // Няма права — няма питлейн (не би трябвало да се случва на реална писта).
    if (span < 30) {
        const fallback = (row) => sign * (half + 1.35);
        return { group, sign, from: 0, to: 0, outerOffset: fallback };
    }

    const taper = Math.min(14, Math.floor(span * 0.2));
    const laneInner = half + 4.2;
    const laneOuter = half + 10.6;

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
            drop: 0.012,
        }),
        new THREE.MeshStandardMaterial({ vertexColors: true, metalness: 0, roughness: 0.92 })
    );
    lane.frustumCulled = false;
    group.add(lane);

    // Пит стената между трасето и лентата — плътна бетонна, като реалната,
    // с телена ограда отгоре. Стената хвърля сянка (сенчестата камера следва
    // колата, така че цената е локална).
    const wallFrom = from + taper + 2;
    const wallTo = to - taper - 2;
    const wall = new THREE.Mesh(
        wallGeometry(track, wallFrom, wallTo, sign * (half + 2.1), 1.05, DECOR.pitWallTop, DECOR.pitWallBottom),
        new THREE.MeshStandardMaterial({ vertexColors: true, metalness: 0.15, roughness: 0.65, side: THREE.DoubleSide })
    );
    wall.frustumCulled = false;
    wall.castShadow = true;
    group.add(wall);

    group.add(fenceMesh(track, wallFrom, wallTo, sign * (half + 2.1), 1.05, 2.6));

    // Бели гранични линии на питлейна: до стената и покрай гаражите.
    const lineOptions = { color: 0xd8d8d8, drop: 0.012 };
    const innerLine = stripGeometry(track, from + taper, to - taper, () => sign * (laneInner + 0.1), () => sign * (laneInner + 0.28), -0.008, lineOptions);
    const outerLine = stripGeometry(track, from + taper, to - taper, () => sign * (laneOuter - 0.28), () => sign * (laneOuter - 0.1), -0.008, lineOptions);
    const lines = mergeGeometries([innerLine, outerLine], false);
    innerLine.dispose();
    outerLine.dispose();
    if (lines) {
        const mesh = new THREE.Mesh(lines, new THREE.MeshBasicMaterial({ vertexColors: true }));
        mesh.frustumCulled = false;
        group.add(mesh);
    }

    // Гаражната сграда: фасада с боксове + плътно тяло с покрив.
    const garFrom = Math.max(from + taper + 3, Math.floor((from + to) / 2) - 32);
    const garTo = Math.min(to - taper - 3, Math.floor((from + to) / 2) + 32);

    if (garTo - garFrom > 10) {
        const frontOffset = sign * (laneOuter + 1.6);
        const backOffset = sign * (laneOuter + 13);
        const height = 8;

        // Едно повторение на текстурата покрива 24 m → бокс от ~6 m, като реален.
        const facadeTexture = makeGarageTexture();
        facadeTexture.repeat.set(((garTo - garFrom) * track.spacing) / 24, 1);

        const facade = new THREE.Mesh(
            wallGeometry(track, garFrom, garTo, frontOffset, height, 0xffffff, 0xffffff),
            new THREE.MeshStandardMaterial({ map: facadeTexture, metalness: 0.1, roughness: 0.7, side: THREE.DoubleSide })
        );
        facade.frustumCulled = false;
        facade.castShadow = true;
        group.add(facade);

        // Тяло: покрив + задна стена + двата края, слети в една геометрия.
        const roof = stripGeometry(track, garFrom, garTo, () => frontOffset, () => backOffset, height, {
            color: DECOR.garageRoof,
            variation: 0.04,
        });
        const back = wallGeometry(track, garFrom, garTo, backOffset, height, DECOR.garageBody, DECOR.garageBody);
        const body = mergeGeometries([roof, back], false);
        roof.dispose();
        back.dispose();

        if (body) {
            const mesh = new THREE.Mesh(
                body,
                new THREE.MeshStandardMaterial({ vertexColors: true, metalness: 0.1, roughness: 0.75, side: THREE.DoubleSide })
            );
            mesh.frustumCulled = false;
            mesh.castShadow = true;
            group.add(mesh);
        }
    }

    return { group, sign, from, to, outerOffset };
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
        const lateral = (j % 2 === 0 ? 1 : -1) * Math.min(3.2, track.width * 0.23);
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

    const mesh = new THREE.Mesh(
        merged,
        new THREE.MeshBasicMaterial({ color: 0xe8e8ea, transparent: true, opacity: 0.85 })
    );
    mesh.frustumCulled = false;

    return mesh;
}

/**
 * Гантри над стартовата линия: две колони, греда, банер „ПАДОК" и петте
 * светлини, които Game пали червени на загряващата обиколка.
 *
 * @param {import('./track.js').Track} track
 * @returns {{group: THREE.Group, lights: THREE.MeshStandardMaterial}}
 */
function buildStartGantry(track) {
    const group = new THREE.Group();
    const half = track.width / 2;
    const p = pointAt(track, 6);
    const yaw = Math.atan2(p.tx, p.tz);

    const frame = new THREE.MeshStandardMaterial({ color: DECOR.gantry, metalness: 0.5, roughness: 0.5 });

    const beamY = 7.2;
    const beamLength = track.width + 5;

    const parts = [];

    for (const s of [-1, 1]) {
        const pillar = new THREE.BoxGeometry(0.7, beamY + 0.6, 0.7);
        pillar.translate(s * (half + 2.0), (beamY + 0.6) / 2, 0);
        parts.push(pillar);
    }

    const beam = new THREE.BoxGeometry(beamLength, 1.0, 1.2);
    beam.translate(0, beamY, 0);
    parts.push(beam);

    const merged = mergeGeometries(parts, false);
    for (const g of parts) {
        g.dispose();
    }
    const structure = new THREE.Mesh(merged, frame);
    structure.castShadow = true;
    group.add(structure);

    // Банер със собствения бранд — единственият „спонсор", който ни е позволен.
    // Леко пред гредата (към прииждащите коли), за да не z-fight-ва с нея.
    const banner = new THREE.Mesh(
        new THREE.PlaneGeometry(beamLength * 0.7, 0.85),
        new THREE.MeshBasicMaterial({ map: makeBannerTexture(), side: THREE.DoubleSide })
    );
    banner.position.set(0, beamY, -0.65);
    // Лицето на плоскостта е по +z (надолу по трасето) — обръщаме го към
    // прииждащите коли, иначе четат текста огледално.
    banner.rotation.y = Math.PI;
    group.add(banner);

    // Петте светлинни модула под гредата — общ емисивен материал, Game го
    // пали (загряваща) и гаси (летяща обиколка).
    const lights = new THREE.MeshStandardMaterial({
        color: 0x17090b,
        emissive: 0xff1f1f,
        emissiveIntensity: 0,
        roughness: 0.55,
    });
    const pods = [];
    for (let i = 0; i < 5; i++) {
        const pod = new THREE.BoxGeometry(0.6, 1.15, 0.4);
        pod.translate((i - 2) * 1.05, beamY - 1.1, -0.4);
        pods.push(pod);
    }
    const podGeo = mergeGeometries(pods, false);
    for (const g of pods) {
        g.dispose();
    }
    group.add(new THREE.Mesh(podGeo, lights));

    group.position.set(p.x, p.y, p.z);
    group.rotation.y = yaw;

    return { group, lights };
}

// ── Завоите: чакъл, табели, гуми, наденички ──────────────────────────────

/**
 * Run-off зони от външната страна на по-бързите завои: чакъл (класика) или
 * асфалтов апрон (модерните писти), според характера на пистата.
 *
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @returns {THREE.Mesh|null}
 */
function buildRunoffZones(track, circuit) {
    const half = track.width / 2;
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
                out * (half + 1.15),
                out * (half + RUNOFF_WIDTH - 0.2),
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

    const mesh = new THREE.Mesh(
        merged,
        gravelly
            ? new THREE.MeshStandardMaterial({ map: makeGravelTexture(), vertexColors: true, metalness: 0, roughness: 1 })
            : new THREE.MeshStandardMaterial({ vertexColors: true, metalness: 0, roughness: 1 })
    );
    mesh.frustumCulled = false;

    return mesh;
}

/**
 * Купчини гуми зад run-off зоните на тежките завои.
 *
 * @param {import('./track.js').Track} track
 * @returns {THREE.InstancedMesh|null}
 */
function buildTyreStacks(track) {
    const { xs, ys, zs, nx, nz, count } = track;
    const half = track.width / 2;
    const ranges = curvatureRanges(track, 0.022, 4);

    if (ranges.length === 0) {
        return null;
    }

    const capacity = 300;
    const geometry = new THREE.CylinderGeometry(0.6, 0.6, 1.15, 9);
    const material = new THREE.MeshStandardMaterial({ color: 0xffffff, metalness: 0, roughness: 0.92 });
    const mesh = new THREE.InstancedMesh(geometry, material, capacity);

    const matrix = new THREE.Matrix4();
    const colour = new THREE.Color();
    const base = new THREE.Color(DECOR.tyre);
    let n = 0;

    for (const range of ranges) {
        const out = -range.side;
        const offset = out * (half + 8.9);

        for (let r = range.from - 6; r <= range.to + 6 && n < capacity; r += 3) {
            const i = ((r % count) + count) % count;

            matrix.makeRotationY(hashNoise(i) * Math.PI);
            matrix.setPosition(
                xs[i] + nx[i] * offset,
                ys[i] + 0.5 - Math.abs(offset) * RUNOFF_DROP,
                zs[i] + nz[i] * offset
            );
            mesh.setMatrixAt(n, matrix);

            // Предимно черни, с редки бели/червени „маркирани" купчини.
            const roll = hashNoise(i * 3.7);
            colour.set(roll > 0.82 ? DECOR.tyreAccent[roll > 0.92 ? 1 : 0] : base);
            mesh.setColorAt(n, colour);

            n++;
        }
    }

    mesh.count = n;
    mesh.instanceMatrix.needsUpdate = true;
    if (mesh.instanceColor) {
        mesh.instanceColor.needsUpdate = true;
    }
    mesh.castShadow = true;
    mesh.frustumCulled = false;

    return mesh;
}

/**
 * Спирачни табели (150/100/50) преди тежките завои + DRS табели на двете
 * най-дълги прави. Табелите с еднакво число делят една инстанцирана геометрия.
 *
 * @param {import('./track.js').Track} track
 * @param {number} pitSign DRS табелите застават срещу питовете, не в питлейна
 * @returns {THREE.Object3D[]}
 */
function buildMarkerBoards(track, pitSign) {
    const { curvature, count, spacing, width } = track;
    const half = width / 2;

    // Шиканите дават два съседни диапазона — една спирачна зона. Сливаме
    // диапазони с произволен знак на разстояние под 12 реда.
    const raw = curvatureRanges(track, 0.02, 3);
    const events = [];
    for (const range of raw) {
        const last = events[events.length - 1];
        if (last && range.from - last.to < 12) {
            last.to = range.to;
        } else {
            events.push({ from: range.from, to: range.to, side: range.side });
        }
    }

    const placements = { 150: [], 100: [], 50: [] };
    const poles = [];

    for (const event of events) {
        const out = -event.side;
        for (const dist of [150, 100, 50]) {
            const row = event.from - Math.round(dist / spacing);
            const i = ((row % count) + count) % count;

            // Табелата стои само на прав участък — вътре в предишния завой не.
            if (Math.abs(curvature[i]) > 0.01) {
                continue;
            }

            placements[dist].push({ i, offset: out * (half + 2.7) });
            poles.push({ i, offset: out * (half + 2.7) });
        }
    }

    // DRS табели на двете най-дълги прави, от страната срещу питовете (на
    // стартовата права табела в питлейна би било нелепо).
    const drs = [];
    for (const run of straightRuns(track, 0.004, 90).slice(0, 2)) {
        const i = (run.from + Math.round(run.len * 0.35)) % count;
        drs.push({ i, offset: -pitSign * (half + 2.7) });
        poles.push({ i, offset: -pitSign * (half + 2.7) });
    }

    const meshes = [];
    const boardGeometry = new THREE.PlaneGeometry(1.0, 0.78);

    const buildSet = (list, texture) => {
        if (list.length === 0) {
            return;
        }
        const material = new THREE.MeshStandardMaterial({ map: texture, roughness: 0.6, side: THREE.DoubleSide });
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
                track.ys[i] + 1.35 - Math.abs(offset) * RUNOFF_DROP,
                track.zs[i] + track.nz[i] * offset
            );
            mesh.setMatrixAt(n, matrix.compose(position, quaternion, scale));
        });

        mesh.instanceMatrix.needsUpdate = true;
        mesh.frustumCulled = false;
        mesh.castShadow = true;
        meshes.push(mesh);
    };

    buildSet(placements[150], makeBoardTexture('150'));
    buildSet(placements[100], makeBoardTexture('100'));
    buildSet(placements[50], makeBoardTexture('50'));
    buildSet(drs, makeBoardTexture('DRS'));

    // Общи колчета под всички табели.
    if (poles.length > 0) {
        const poleGeometry = new THREE.BoxGeometry(0.1, 1.0, 0.1);
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
                track.ys[i] + 0.5 - Math.abs(offset) * RUNOFF_DROP,
                track.zs[i] + track.nz[i] * offset
            );
            poleMesh.setMatrixAt(n, matrix);
        });
        poleMesh.instanceMatrix.needsUpdate = true;
        poleMesh.frustumCulled = false;
        poleMesh.castShadow = true;
        meshes.push(poleMesh);
    }

    return meshes;
}

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
function straightRuns(track, maxCurv, minRows) {
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
 * Пешеходен мост над най-дългата права — пасарелките с реклами са част от
 * силуета на всяка постоянна писта. Прескача правите, чиято среда попада в
 * пит зоната (на Монца и Зандвоорт най-дългата права Е стартовата — кулата
 * на моста иначе стъпва в питлейна).
 *
 * @param {import('./track.js').Track} track
 * @param {{from: number, to: number}} pit Диапазонът на пит комплекса в редове
 * @returns {THREE.Mesh|null}
 */
function buildFootbridge(track, pit) {
    const { count, width } = track;
    const half = width / 2;

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

    const span = 2 * (half + 6);
    const deckY = 6.2;
    const parts = [];

    for (const s of [-1, 1]) {
        // Кулите продължават под нулата — на банкета теренът е спуснат.
        const tower = new THREE.BoxGeometry(1.4, deckY + 2.4, 1.4);
        tower.translate(s * (half + 5), (deckY + 2.4) / 2 - 1.2, 0);
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
        new THREE.MeshStandardMaterial({ vertexColors: true, metalness: 0.35, roughness: 0.6 })
    );
    mesh.position.set(track.xs[i], track.ys[i], track.zs[i]);
    mesh.rotation.y = Math.atan2(track.tx[i], track.tz[i]);
    mesh.castShadow = true;

    return mesh;
}

/**
 * Грейд-сепарации, детектирани от самопресичането на трасето в план — на
 * практика мостът на осмицата на Сузука. Строи греда под горното платно и
 * два пилона край долното.
 *
 * @param {import('./track.js').Track} track
 * @returns {THREE.Object3D[]}
 */
function buildCrossoverBridges(track) {
    const { xs, ys, zs, count, width } = track;
    const out = [];
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

    const half = width / 2;

    for (const crossing of found) {
        const up = crossing.upper;
        const low = crossing.lower;
        const gap = ys[up] - ys[low];
        const parts = [];

        // Греда под горното платно, по неговата посока.
        const girder = new THREE.BoxGeometry(width + 3.5, 1.3, 26);
        girder.translate(0, -0.75, 0);
        paintGeometryFlat(girder, 0x5c6168);
        parts.push(girder);

        // Странични фасции — четат се като мост от долния път.
        for (const s of [-1, 1]) {
            const fascia = new THREE.BoxGeometry(0.3, 1.7, 26);
            fascia.translate(s * (half + 1.9), -0.2, 0);
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

        const bridge = new THREE.Mesh(
            geometry,
            new THREE.MeshStandardMaterial({ vertexColors: true, metalness: 0.2, roughness: 0.7 })
        );
        bridge.position.set(xs[up], ys[up], zs[up]);
        bridge.rotation.y = Math.atan2(track.tx[up], track.tz[up]);
        out.push(bridge);

        // Пилони край долното платно, до опорите на гредата.
        const pillarGeo = [];
        for (const s of [-1, 1]) {
            const pillar = new THREE.BoxGeometry(1.3, gap - 1.6, 1.3);
            pillar.translate(s * (half + 2.6), (gap - 1.6) / 2, 0);
            paintGeometryFlat(pillar, 0x6e747c);
            pillarGeo.push(pillar);
        }
        const pillars = mergeGeometries(pillarGeo, false);
        for (const part of pillarGeo) {
            part.dispose();
        }
        if (pillars) {
            const mesh = new THREE.Mesh(
                pillars,
                new THREE.MeshStandardMaterial({ vertexColors: true, metalness: 0.2, roughness: 0.7 })
            );
            mesh.position.set(xs[low], ys[low], zs[low]);
            mesh.rotation.y = Math.atan2(track.tx[low], track.tz[low]);
            out.push(mesh);
        }
    }

    return out;
}

/** z-компонент на 2D векторно произведение. */
function cross2(ax, az, bx, bz) {
    return ax * bz - az * bx;
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
 * Оранжеви „sausage" кербове зад върха на шиканите — подписът на Монца,
 * Силвърстоун и Casio Triangle на Сузука.
 *
 * @param {import('./track.js').Track} track
 * @returns {THREE.InstancedMesh|null}
 */
function buildSausageKerbs(track) {
    const { xs, ys, zs, nx, nz, count, width } = track;
    const half = width / 2;

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
        const offset = range.side * (half + 1.1 + 0.75); // зад вътрешния керб

        for (let r = range.from; r <= range.to && n < capacity; r += 2) {
            const i = ((r % count) + count) % count;

            quaternion.setFromAxisAngle(UP, Math.atan2(track.tx[i], track.tz[i]));
            position.set(xs[i] + nx[i] * offset, ys[i] + 0.11, zs[i] + nz[i] * offset);
            mesh.setMatrixAt(n, matrix.compose(position, quaternion, scale));
            n++;
        }
    }

    mesh.count = n;
    mesh.instanceMatrix.needsUpdate = true;
    mesh.frustumCulled = false;
    mesh.castShadow = true;

    return mesh;
}

// ── Градски стени, идеална линия ─────────────────────────────────────────

/**
 * Мантинели плътно по двете страни на цялото трасе (градска писта). От
 * страната на питовете стената обикаля питлейна.
 *
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @param {{sign: number, from: number, to: number, outerOffset: (row: number) => number}} pit
 * @returns {THREE.Mesh}
 */
function buildStreetWalls(track, circuit, pit) {
    const half = track.width / 2;
    const base = half + 1.45;

    const nonPitSide = wallGeometry(track, 0, track.count, -pit.sign * base, 0.95, DECOR.pitWallTop, DECOR.pitWallBottom);

    // Откъм питовете: следва външния ръб на питлейна в неговия диапазон.
    const pitSide = wallGeometry(
        track,
        0,
        track.count,
        (row) => {
            const wrapped = row > track.count / 2 ? row - track.count : row;
            if (wrapped > pit.from && wrapped < pit.to) {
                return pit.outerOffset(wrapped) + pit.sign * 0.9;
            }
            return pit.sign * base;
        },
        0.95,
        DECOR.pitWallTop,
        DECOR.pitWallBottom
    );

    const merged = mergeGeometries([nonPitSide, pitSide], false);
    nonPitSide.dispose();
    pitSide.dispose();

    const mesh = new THREE.Mesh(
        merged,
        new THREE.MeshStandardMaterial({ vertexColors: true, metalness: 0.45, roughness: 0.5, side: THREE.DoubleSide })
    );
    mesh.frustumCulled = false;
    mesh.castShadow = true;

    return mesh;
}

/**
 * Страничното отместване на идеалната линия за всяка осева точка: клони към
 * вътрешността на завоя и е силно изгладено, за да се получат плавните
 * излизания навън преди и след върха (out-in-out).
 *
 * @param {import('./track.js').Track} track
 * @returns {Float32Array}
 */
function computeRacingLine(track) {
    const { curvature, count, width } = track;
    const reach = width / 2 - 1.9;

    let offsets = new Float32Array(count);
    for (let i = 0; i < count; i++) {
        const k = curvature[i] / 0.012;
        offsets[i] = reach * Math.max(-1, Math.min(1, k));
    }

    return smoothCyclicWide(offsets, 4, 24);
}

/**
 * Гумираната идеална линия: тъмна полупрозрачна лента, която реже върховете
 * на завоите — следата, която реалните болиди оставят за един уикенд.
 *
 * @param {import('./track.js').Track} track
 * @param {Float32Array} offsets computeRacingLine
 * @returns {THREE.Mesh}
 */
function buildRacingLine(track, offsets) {
    const geometry = stripGeometry(
        track,
        0,
        track.count,
        (row, i) => offsets[i] - 1.15,
        (row, i) => offsets[i] + 1.15,
        Y_RACING_LINE,
        { color: 0x0a0a0c }
    );

    const mesh = new THREE.Mesh(
        geometry,
        new THREE.MeshBasicMaterial({ color: 0x000000, transparent: true, opacity: 0.16, depthWrite: false })
    );
    mesh.frustumCulled = false;
    mesh.renderOrder = 1;

    return mesh;
}

/**
 * Следи от гуми в спирачните зони: двойка тъмни ивици по идеалната линия,
 * които се сгъстяват към входа на завоя — най-силният визуален сигнал „тук
 * се спира" от телевизионния кадър.
 *
 * Алфата е върху върховете (RGBA цветове) — следата избледнява назад, без
 * рязък ръб там, където почва.
 *
 * @param {import('./track.js').Track} track
 * @param {Float32Array} offsets computeRacingLine
 * @returns {THREE.Mesh|null}
 */
function buildBrakeMarks(track, offsets) {
    const { xs, ys, zs, nx, nz, count, spacing } = track;

    // Същите спирачни събития като табелите: слети диапазони с тесен завой.
    const raw = curvatureRanges(track, 0.02, 3);
    const events = [];
    for (const range of raw) {
        const last = events[events.length - 1];
        if (last && range.from - last.to < 12) {
            last.to = range.to;
        } else {
            events.push({ from: range.from, to: range.to });
        }
    }

    if (events.length === 0) {
        return null;
    }

    const positions = [];
    const colors = [];
    const indices = [];

    const lead = Math.round(110 / spacing); // следата почва ~110 m преди завоя
    const tail = Math.round(12 / spacing); //  и продължава малко след входа

    for (const event of events) {
        for (const lane of [-0.55, 0.55]) {
            const startVertex = positions.length / 3;
            const rows = lead + tail + 1;

            for (let r = 0; r < rows; r++) {
                const row = event.from - lead + r;
                const i = ((row % count) + count) % count;
                const center = offsets[i] + lane;

                // 0 в началото → плътно на входа на завоя.
                const strength = smoothstep(0.15, 0.95, r / rows) * 0.5;

                for (const side of [-0.2, 0.2]) {
                    const offset = center + side;
                    positions.push(
                        xs[i] + nx[i] * offset,
                        ys[i] + Y_RACING_LINE + 0.002,
                        zs[i] + nz[i] * offset
                    );
                    colors.push(0.02, 0.02, 0.025, strength);
                }

                if (r > 0) {
                    const a = startVertex + (r - 1) * 2;
                    indices.push(a, a + 1, a + 2, a + 1, a + 3, a + 2);
                }
            }
        }
    }

    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
    geometry.setAttribute('color', new THREE.Float32BufferAttribute(colors, 4));
    geometry.setIndex(indices);
    geometry.computeBoundingSphere();

    const mesh = new THREE.Mesh(
        geometry,
        new THREE.MeshBasicMaterial({ vertexColors: true, transparent: true, depthWrite: false })
    );
    mesh.frustumCulled = false;
    mesh.renderOrder = 1;

    return mesh;
}

// ── Терен и ориентири ────────────────────────────────────────────────────

/**
 * Суровата височина на терена в дадена точка: близо до трасето следва банкета,
 * далече се надига по детерминиран шум (дюните на Зандвоорт, хълмовете на
 * Шпилберг). Ползва се САМО при построяване на мрежата на семплера — всичко
 * останало чете през семплера, за да стои върху една и съща повърхност.
 *
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @param {number} x
 * @param {number} z
 * @returns {number}
 */
function rawTerrainHeight(track, circuit, x, z) {
    const { xs, ys, zs, count } = track;

    let bestY = 0;
    let bestDistSq = Infinity;

    for (let i = 0; i < count; i += 8) {
        const dx = x - xs[i];
        const dz = z - zs[i];
        const distSq = dx * dx + dz * dz;

        if (distSq < bestDistSq) {
            bestDistSq = distSq;
            bestY = ys[i];
        }
    }

    const dist = Math.sqrt(bestDistSq);
    const drop = Math.min(dist, 30) * RUNOFF_DROP;
    // Колко далече от трасето започва релефът: дюните на Зандвоорт опират
    // почти до банкета, алпийските хълмове стоят по-назад.
    const ramp = smoothstep(
        circuit.terrain.rampNear ?? 60,
        circuit.terrain.rampFar ?? 220,
        dist
    );
    const relief = (fbm(x, z) - 0.3) * circuit.terrain.amplitude;

    return bestY - drop - 0.35 + relief * ramp;
}

/**
 * @typedef {object} TerrainSampler
 * @property {(x: number, z: number) => number} heightAt Билинейна височина
 */

/**
 * Прекомпютва мрежата на терена веднъж и я споделя между мрежестия terrain
 * mesh, дърветата, сградите и ориентирите. Без общия семплер обектите четяха
 * НЕПРЕКЪСНАТАТА функция, а мрежата — само възлите ѝ на ~35 m: на хълмиста
 * писта дърво между два възела висеше или потъваше с метри.
 *
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @returns {TerrainSampler & object}
 */
export function createTerrainSampler(track, circuit) {
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

    const margin = 700;
    const sizeX = maxX - minX + margin * 2;
    const sizeZ = maxZ - minZ + margin * 2;
    const centerX = (minX + maxX) / 2;
    const centerZ = (minZ + maxZ) / 2;
    const res = 110;
    const n = res + 1;
    const x0 = centerX - sizeX / 2;
    const z0 = centerZ - sizeZ / 2;
    const stepX = sizeX / res;
    const stepZ = sizeZ / res;

    // Потискане на релефа под водата на пристанището (Монако) — в мрежата,
    // за да важи еднакво за терена И за обектите върху него.
    const harbor = circuit.landmark?.type === 'harbor' ? harborFrame(track, circuit.landmark) : null;

    const heights = new Float32Array(n * n);

    for (let iz = 0; iz < n; iz++) {
        for (let ix = 0; ix < n; ix++) {
            const x = x0 + ix * stepX;
            const z = z0 + iz * stepZ;
            let h = rawTerrainHeight(track, circuit, x, z);

            if (harbor) {
                const local = harbor.toLocal(x, z);
                const outside = Math.max(
                    Math.abs(local.u) - harbor.halfW,
                    Math.abs(local.v) - harbor.halfD
                );
                if (outside < 40) {
                    // Плавно снишаване към дъното на залива.
                    const sink = 1 - clamp01(outside / 40);
                    h = Math.min(h, h * (1 - sink) + (harbor.waterY - 2.5) * sink);
                }
            }

            heights[iz * n + ix] = h;
        }
    }

    return {
        sizeX,
        sizeZ,
        centerX,
        centerZ,
        res,
        heightAt(x, z) {
            const u = Math.min(res - 1e-6, Math.max(0, (x - x0) / stepX));
            const v = Math.min(res - 1e-6, Math.max(0, (z - z0) / stepZ));
            const ix = Math.floor(u);
            const iz = Math.floor(v);
            const fx = u - ix;
            const fz = v - iz;

            const h00 = heights[iz * n + ix];
            const h10 = heights[iz * n + ix + 1];
            const h01 = heights[(iz + 1) * n + ix];
            const h11 = heights[(iz + 1) * n + ix + 1];

            const a = h00 + (h10 - h00) * fx;
            const b = h01 + (h11 - h01) * fx;

            return a + (b - a) * fz;
        },
    };
}

/**
 * Теренът около трасето: мрежа върху цялата площ, с релеф от семплера и
 * палитра според пистата. Далечният хоризонт остава за плоскостта в mesh.js.
 *
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @param {TerrainSampler & object} sampler
 * @returns {THREE.Mesh}
 */
function buildTerrain(track, circuit, sampler) {
    const geometry = new THREE.PlaneGeometry(sampler.sizeX, sampler.sizeZ, sampler.res, sampler.res);
    geometry.rotateX(-Math.PI / 2);

    const positions = geometry.attributes.position;
    const colors = new Float32Array(positions.count * 3);
    const base = new THREE.Color(circuit.terrain.base);
    const accent = new THREE.Color(circuit.terrain.accent);
    const mixed = new THREE.Color();

    for (let v = 0; v < positions.count; v++) {
        const x = positions.getX(v) + sampler.centerX;
        const z = positions.getZ(v) + sampler.centerZ;

        positions.setX(v, x);
        positions.setY(v, sampler.heightAt(x, z));
        positions.setZ(v, z);

        const tone = clamp01(fbm(x + 4096, z - 4096) * 1.5 - 0.25);
        mixed.copy(base).lerp(accent, tone);
        const shade = 0.92 + hashNoise(v) * 0.16;
        colors[v * 3] = clamp01(mixed.r * shade);
        colors[v * 3 + 1] = clamp01(mixed.g * shade);
        colors[v * 3 + 2] = clamp01(mixed.b * shade);
    }

    geometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));
    geometry.computeVertexNormals();
    geometry.computeBoundingSphere();

    const mesh = new THREE.Mesh(
        geometry,
        new THREE.MeshStandardMaterial({ vertexColors: true, metalness: 0, roughness: 1 })
    );
    mesh.frustumCulled = false;

    return mesh;
}

/**
 * Локалната рамка на пристанището: център, ориентация по трасето и превръщане
 * на световни координати в локални (u напречно, v по протежение).
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
        // u по нормалата (сравнява се с halfW), v по тангентата (с halfD) —
        // същата рамка като водната плоскост и яхтите.
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
 * Пристанището на Монако: водна плоскост със слънчеви отблясъци и бели яхти.
 *
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @returns {THREE.Group}
 */
function buildHarbor(track, circuit) {
    const cfg = circuit.landmark;
    const frame = harborFrame(track, cfg);
    const group = new THREE.Group();

    // Ширината ляга по нормалата на трасето, дълбочината — по тангентата
    // (същата рамка като harborFrame.toLocal, за да съвпадне с терена).
    const waterGeometry = new THREE.PlaneGeometry(cfg.width, cfg.depth);
    waterGeometry.rotateX(-Math.PI / 2);
    const water = new THREE.Mesh(
        waterGeometry,
        new THREE.MeshStandardMaterial({ color: DECOR.water, metalness: 0.4, roughness: 0.15 })
    );
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

    yachts.instanceMatrix.needsUpdate = true;
    yachts.frustumCulled = false;
    group.add(yachts);

    return group;
}

/**
 * Ред развети знамена срещу питовете, около старт/финала. Всяко знаме е
 * плоскост на пилон; веенето е синусоида с фазово отместване по ред —
 * анимира се от Game.#frame през върнатата функция.
 *
 * @param {import('./track.js').Track} track
 * @param {{sign: number}} pit
 * @returns {{group: THREE.Group, animate: (dt: number) => void}}
 */
function buildStartFlags(track, pit) {
    const group = new THREE.Group();
    const half = track.width / 2;
    const side = -pit.sign;
    const offset = side * (half + 3.4);

    const pole = new THREE.CylinderGeometry(0.05, 0.05, 5.2, 6);
    pole.translate(0, 2.6, 0);
    const poleMaterial = new THREE.MeshStandardMaterial({ color: 0x9aa0a8, metalness: 0.6, roughness: 0.4 });

    const bg = makeTricolourTexture();
    const palette = [0xd42a26, 0xe6e6e6, 0x2470b8, 0xe0a83a];
    const pivots = [];

    for (let f = 0; f < 7; f++) {
        const m = -36 + f * 14;
        const p = pointAt(track, m);

        const holder = new THREE.Group();
        holder.position.set(p.x + p.nx * offset, p.y - Math.abs(offset) * RUNOFF_DROP, p.z + p.nz * offset);
        holder.rotation.y = Math.atan2(p.tx, p.tz);

        holder.add(new THREE.Mesh(pole, poleMaterial));

        const cloth = new THREE.PlaneGeometry(1.3, 0.8);
        cloth.translate(0.65, 0, 0);
        const material =
            f % 3 === 0
                ? new THREE.MeshStandardMaterial({ map: bg, side: THREE.DoubleSide, roughness: 0.9 })
                : new THREE.MeshStandardMaterial({ color: palette[f % palette.length], side: THREE.DoubleSide, roughness: 0.9 });

        const flag = new THREE.Mesh(cloth, material);
        flag.position.y = 4.6;
        holder.add(flag);
        pivots.push(flag);

        group.add(holder);
    }

    let t = 0;
    const animate = (dt) => {
        t += dt;
        for (let k = 0; k < pivots.length; k++) {
            pivots[k].rotation.y = Math.sin(t * 2.6 + k * 1.7) * 0.45;
            pivots[k].rotation.z = Math.sin(t * 3.4 + k * 0.9) * 0.12;
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
 * @param {TerrainSampler & object} sampler
 * @returns {{group: THREE.Group, animate: (dt: number) => void}}
 */
function buildFerrisWheel(track, circuit, sampler) {
    const cfg = circuit.landmark;
    const p = pointAt(track, cfg.along);
    const cx = p.x + p.nx * cfg.side * cfg.dist;
    const cz = p.z + p.nz * cfg.side * cfg.dist;
    const baseY = sampler.heightAt(cx, cz);

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
    // кадър, за да останат изправени.
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

// ── Текстури (canvas, детерминирани) ─────────────────────────────────────

/**
 * Фасада на пит гаражите: тъмни боксове, светли колони, лента с генерични
 * цветни блокове (без реални лога).
 *
 * @returns {THREE.CanvasTexture}
 */
function makeGarageTexture() {
    const w = 512;
    const h = 128;
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');

    // Тяло.
    ctx.fillStyle = '#b0b3ba';
    ctx.fillRect(0, 0, w, h);

    // Боксове (вратите заемат долните 2/3).
    const bays = 4;
    const bayW = w / bays;
    for (let b = 0; b < bays; b++) {
        const x = b * bayW;
        ctx.fillStyle = '#20242b';
        ctx.fillRect(x + 10, h * 0.34, bayW - 20, h * 0.66);
        // Генерична цветна лента над бокса — „отборът" на бокса.
        const colors = ['#c0392b', '#2980b9', '#16a085', '#e67e22', '#8e44ad', '#2c3e50'];
        ctx.fillStyle = colors[Math.floor(hashNoise(b * 13.7) * colors.length)];
        ctx.fillRect(x + 10, h * 0.2, bayW - 20, h * 0.12);
    }

    const texture = new THREE.CanvasTexture(canvas);
    texture.wrapS = THREE.RepeatWrapping;
    texture.wrapT = THREE.ClampToEdgeWrapping;
    texture.colorSpace = THREE.SRGBColorSpace;

    return texture;
}

/**
 * Защитна ограда (catch fence): полупрозрачна мрежа върху лента от стена.
 * Ползва се пред трибуните и върху пит стената — детайлът, който отличава
 * писта от шосе. Експортирана, защото трибуните живеят в mesh.js.
 *
 * @param {import('./track.js').Track} track
 * @param {number} rowFrom
 * @param {number} rowTo
 * @param {number|((row: number, i: number) => number)} offsetFn
 * @param {number} bottom Долен ръб над платното
 * @param {number} top Горен ръб над платното
 * @returns {THREE.Mesh}
 */
export function fenceMesh(track, rowFrom, rowTo, offsetFn, bottom, top) {
    const geometry = wallGeometry(track, rowFrom, rowTo, offsetFn, top, 0xffffff, 0xffffff, bottom);

    const texture = makeFenceTexture();
    texture.repeat.set(((rowTo - rowFrom) * track.spacing) / 6, 1);

    const mesh = new THREE.Mesh(
        geometry,
        new THREE.MeshStandardMaterial({
            map: texture,
            alphaTest: 0.35,
            side: THREE.DoubleSide,
            metalness: 0.5,
            roughness: 0.55,
        })
    );
    mesh.frustumCulled = false;

    return mesh;
}

/**
 * Текстура на телена мрежа: диагонални нишки + горна греда + стълб на ръба
 * на всяко повторение, върху прозрачен фон (alphaTest реже фона).
 *
 * @returns {THREE.CanvasTexture}
 */
function makeFenceTexture() {
    const size = 128;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');

    ctx.clearRect(0, 0, size, size);
    ctx.strokeStyle = 'rgba(70, 74, 80, 0.9)';
    ctx.lineWidth = 2;

    // Ромбовидна мрежа: два комплекта диагонали.
    for (let d = -size; d < size * 2; d += 16) {
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
    ctx.fillRect(0, 0, 5, size);
    ctx.fillRect(0, 0, size, 5);

    const texture = new THREE.CanvasTexture(canvas);
    texture.wrapS = THREE.RepeatWrapping;
    texture.wrapT = THREE.ClampToEdgeWrapping;

    return texture;
}

/**
 * Текстура на чакъл: пясъчна основа с хиляди зрънца и по-едри камъчета.
 *
 * @returns {THREE.CanvasTexture}
 */
function makeGravelTexture() {
    const size = 256;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');

    ctx.fillStyle = '#b9a878';
    ctx.fillRect(0, 0, size, size);

    for (let i = 0; i < 3200; i++) {
        const shade = hashNoise(i * 1.91);
        ctx.fillStyle = shade > 0.5
            ? `rgba(255, 246, 224, ${0.25 + shade * 0.3})`
            : `rgba(74, 64, 46, ${0.2 + shade * 0.4})`;
        ctx.fillRect(hashNoise(i * 2.71) * size, hashNoise(i * 3.37) * size, 2, 2);
    }

    for (let i = 0; i < 240; i++) {
        ctx.fillStyle = hashNoise(i * 5.3) > 0.5 ? '#a08e64' : '#cdbd92';
        ctx.beginPath();
        ctx.arc(hashNoise(i * 7.7) * size, hashNoise(i * 9.1) * size, 1.5 + hashNoise(i * 11.3) * 1.6, 0, Math.PI * 2);
        ctx.fill();
    }

    const texture = new THREE.CanvasTexture(canvas);
    texture.wrapS = THREE.RepeatWrapping;
    texture.wrapT = THREE.RepeatWrapping;
    texture.colorSpace = THREE.SRGBColorSpace;
    texture.repeat.set(2, 2);

    return texture;
}

/**
 * Табела с число (150/100/50) или „DRS" — бяло върху синьо, бяла рамка.
 *
 * @param {string} label
 * @returns {THREE.CanvasTexture}
 */
function makeBoardTexture(label) {
    const w = 256;
    const h = 200;
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');

    ctx.fillStyle = '#1c2f6e';
    ctx.fillRect(0, 0, w, h);
    ctx.strokeStyle = '#f2f2f2';
    ctx.lineWidth = 10;
    ctx.strokeRect(8, 8, w - 16, h - 16);

    ctx.fillStyle = '#ffffff';
    ctx.font = 'bold 96px sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(label, w / 2, h / 2 + 4);

    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.SRGBColorSpace;

    return texture;
}

/**
 * Банер за гантрито: „ПАДОК" + червената точка — собственият бранд.
 *
 * @returns {THREE.CanvasTexture}
 */
function makeBannerTexture() {
    const w = 1024;
    const h = 96;
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');

    ctx.fillStyle = '#141518';
    ctx.fillRect(0, 0, w, h);

    ctx.fillStyle = '#f2f2f2';
    ctx.font = 'bold 64px sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    const label = 'ПАДОК';
    ctx.fillText(label, w / 2, h / 2 + 2);

    // Червената точка от логото, след името.
    const textWidth = ctx.measureText(label).width;
    ctx.fillStyle = '#d42a26';
    ctx.beginPath();
    ctx.arc(w / 2 + textWidth / 2 + 26, h / 2 + 18, 9, 0, Math.PI * 2);
    ctx.fill();

    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.SRGBColorSpace;

    return texture;
}

// ── Дребни числови помощници ─────────────────────────────────────────────

const UP = new THREE.Vector3(0, 1, 0);

/**
 * Широко циклично изглаждане: плъзгащ прозорец с радиус `radius`, `passes`
 * пъти. Кумулативна сума на всяко минаване → O(n) на минаване.
 *
 * @param {Float32Array} values
 * @param {number} radius
 * @param {number} passes
 * @returns {Float32Array}
 */
function smoothCyclicWide(values, radius, passes) {
    const n = values.length;
    let current = values;
    const window = radius * 2 + 1;

    for (let pass = 0; pass < passes; pass++) {
        const out = new Float32Array(n);
        let sum = 0;

        for (let i = -radius; i <= radius; i++) {
            sum += current[((i % n) + n) % n];
        }

        for (let i = 0; i < n; i++) {
            out[i] = sum / window;
            const drop = ((i - radius) % n + n) % n;
            const add = ((i + radius + 1) % n + n) % n;
            sum += current[add] - current[drop];
        }

        current = out;
    }

    return current;
}

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

/** Три октави value noise — достатъчно за хълмове и дюни. */
function fbm(x, z) {
    return (
        noise2(x / 340, z / 340) * 0.55 +
        noise2(x / 110, z / 110) * 0.3 +
        noise2(x / 34, z / 34) * 0.15
    );
}

/**
 * @param {number} edge0
 * @param {number} edge1
 * @param {number} v
 * @returns {number}
 */
function smoothstep(edge0, edge1, v) {
    const t = clamp01((v - edge0) / (edge1 - edge0));

    return t * t * (3 - 2 * t);
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
