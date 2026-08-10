/**
 * Процедурно генериране на 3D геометрията на пистата.
 *
 * Нищо не се зарежда като готов модел — всичко се извежда от осевата линия,
 * височинния профил и кривината. Така всяка нова писта е един JSON файл, не
 * часове моделиране.
 */

import * as THREE from 'three';
import { mergeGeometries } from 'three/examples/jsm/utils/BufferGeometryUtils.js';
import { findKerbRanges } from './track.js';

export const COLORS = {
    asphalt: 0x35353b,
    edgeLine: 0xe8e8ea,
    kerbRed: 0xd42a26,
    kerbWhite: 0xf2f2f2,
    grass: 0x1e3a24,
    ground: 0x16241a,
    startLine: 0xf2f2f2,
    marker: 0xe8e8ea,
    markerAlt: 0xd42a26,
    sky: 0x9fc4de,
    grandstand: 0x9aa0a8,
    grandstandRoof: 0x4a5058,
    building: 0x8d8579,
    trunk: 0x4a3728,
    foliage: 0x2f5233,
};

/** Височини за extrude на ориентирите, метри. */
const LANDMARK_HEIGHT = {
    grandstand: 11.0,
    building: 7.5,
};

/** Ширина на белите ръбови линии, метри. */
const EDGE_LINE_WIDTH = 0.18;

/** Ширина на кербовете, метри. */
const KERB_WIDTH = 1.1;

/** Дължина на едно червено/бяло блокче на керба, метри. */
const KERB_BLOCK = 2.0;

/** Колко навън се простира тревата от ръба на трасето, метри. */
const RUNOFF_WIDTH = 60;

/**
 * Спад на тревата на метър навън. Без него run-off зоната е плосък диск около
 * наклонено трасе и виси във въздуха по склоновете.
 */
const RUNOFF_DROP = 0.035;

/** Вертикално нареждане на слоевете — предпазва от z-fighting. */
const Y = {
    grass: -0.12,
    asphalt: 0.0,
    edgeLine: 0.012,
    kerb: 0.02,
    startLine: 0.014,
};

/**
 * Генерира цялата статична геометрия на пистата.
 *
 * @param {import('./track.js').Track} track
 * @returns {THREE.Group}
 */
export function buildTrackMeshes(track) {
    const group = new THREE.Group();
    const half = track.width / 2;

    group.add(buildGround(track));
    group.add(
        ribbonMesh(track, -(half + RUNOFF_WIDTH), half + RUNOFF_WIDTH, Y.grass, {
            color: COLORS.grass,
            variation: 0.1,
            drop: RUNOFF_DROP,
        })
    );
    group.add(
        ribbonMesh(track, -half, half, Y.asphalt, {
            color: COLORS.asphalt,
            variation: 0.06,
        })
    );
    group.add(
        ribbonMesh(track, half - EDGE_LINE_WIDTH, half, Y.edgeLine, { color: COLORS.edgeLine })
    );
    group.add(
        ribbonMesh(track, -half, -half + EDGE_LINE_WIDTH, Y.edgeLine, { color: COLORS.edgeLine })
    );
    group.add(buildKerbs(track));
    group.add(buildStartLine(track));
    group.add(buildDistanceMarkers(track));

    for (const mesh of buildLandmarks(track)) {
        group.add(mesh);
    }

    return group;
}

/**
 * Реалните трибуни, сгради и гори около пистата, от OpenStreetMap.
 *
 * Това е разликата между „някакво трасе с правилната форма" и разпознаваемо
 * място: стените от трибуни покрай стартовата права на Монца или гората на
 * Спа се четат от една снимка.
 *
 * @param {import('./track.js').Track} track
 * @returns {THREE.Object3D[]}
 */
function buildLandmarks(track) {
    const landmarks = track.landmarks;

    if (!landmarks) {
        return [];
    }

    const out = [];

    const grandstands = extrudeRings(
        track,
        landmarks.grandstands ?? [],
        LANDMARK_HEIGHT.grandstand,
        COLORS.grandstand
    );
    if (grandstands) {
        out.push(grandstands);
    }

    const buildings = extrudeRings(
        track,
        landmarks.buildings ?? [],
        LANDMARK_HEIGHT.building,
        COLORS.building
    );
    if (buildings) {
        out.push(buildings);
    }

    const trees = buildTrees(track, landmarks.trees ?? []);
    if (trees) {
        out.push(trees);
    }

    return out;
}

/**
 * Издига контурите в обеми и ги слива в един mesh.
 *
 * Сливането не е разкош: 160 отделни сгради са 160 draw call-а и сами по себе
 * си свалят кадрите на телефон под играбилното.
 *
 * @param {import('./track.js').Track} track
 * @param {Array<Array<Array<number>>>} rings
 * @param {number} baseHeight
 * @param {number} color
 * @returns {THREE.Mesh|null}
 */
function extrudeRings(track, rings, baseHeight, color) {
    if (rings.length === 0) {
        return null;
    }

    const geometries = [];

    for (let r = 0; r < rings.length; r++) {
        const ring = rings[r];

        if (ring.length < 3) {
            continue;
        }

        // Shape живее в XY; z се обръща, за да съвпадне с XZ след ротацията.
        const shape = new THREE.Shape(
            ring.map(([x, z]) => new THREE.Vector2(x, -z))
        );

        // Лека вариация, за да не е равен блок от еднакви кутии.
        const height = baseHeight * (0.75 + hashNoise(r * 7 + 1) * 0.6);

        let geometry;
        try {
            geometry = new THREE.ExtrudeGeometry(shape, {
                depth: height,
                bevelEnabled: false,
                curveSegments: 1,
            });
        } catch {
            // Самопресичащ се контур — OSM ги има; пропускаме тихо.
            continue;
        }

        geometry.rotateX(-Math.PI / 2);

        // Сядат на нивото на най-близката част от трасето. Точен терен нямаме
        // и не ни трябва — на 400 m разстояние разликата не се чете.
        const centroid = ringCentroid(ring);
        geometry.translate(0, groundHeightNear(track, centroid[0], centroid[1]), 0);

        geometries.push(geometry);
    }

    if (geometries.length === 0) {
        return null;
    }

    const merged = mergeGeometries(geometries, false);

    for (const geometry of geometries) {
        geometry.dispose();
    }

    if (!merged) {
        return null;
    }

    merged.computeVertexNormals();

    const mesh = new THREE.Mesh(merged, new THREE.MeshLambertMaterial({ color }));
    mesh.frustumCulled = false;

    return mesh;
}

/**
 * Дървета в горските зони, като инстанции на един прост силует.
 *
 * @param {import('./track.js').Track} track
 * @param {Array<Array<number>>} trees
 * @returns {THREE.InstancedMesh|null}
 */
function buildTrees(track, trees) {
    if (trees.length === 0) {
        return null;
    }

    const trunk = new THREE.CylinderGeometry(0.22, 0.3, 2.4, 5);
    trunk.translate(0, 1.2, 0);

    const foliage = new THREE.ConeGeometry(2.1, 6.5, 6);
    foliage.translate(0, 5.6, 0);

    // Два цвята в една геометрия: по-евтино от два InstancedMesh-а.
    paintGeometry(trunk, COLORS.trunk);
    paintGeometry(foliage, COLORS.foliage);

    const geometry = mergeGeometries([trunk, foliage], false);
    trunk.dispose();
    foliage.dispose();

    if (!geometry) {
        return null;
    }

    const mesh = new THREE.InstancedMesh(
        geometry,
        new THREE.MeshLambertMaterial({ vertexColors: true }),
        trees.length
    );

    const matrix = new THREE.Matrix4();
    const position = new THREE.Vector3();
    const quaternion = new THREE.Quaternion();
    const scale = new THREE.Vector3();

    for (let i = 0; i < trees.length; i++) {
        const [x, z, s] = trees[i];

        position.set(x, groundHeightNear(track, x, z) - 0.2, z);
        quaternion.setFromAxisAngle(UP, hashNoise(i) * Math.PI * 2);
        scale.set(s, s * (0.85 + hashNoise(i * 3) * 0.4), s);

        matrix.compose(position, quaternion, scale);
        mesh.setMatrixAt(i, matrix);
    }

    mesh.instanceMatrix.needsUpdate = true;
    mesh.frustumCulled = false;

    return mesh;
}

const UP = new THREE.Vector3(0, 1, 0);

/**
 * Слага плътен vertex color на цялата геометрия.
 *
 * @param {THREE.BufferGeometry} geometry
 * @param {number} color
 */
function paintGeometry(geometry, color) {
    const count = geometry.attributes.position.count;
    const c = new THREE.Color(color);
    const colors = new Float32Array(count * 3);

    for (let i = 0; i < count; i++) {
        colors[i * 3] = c.r;
        colors[i * 3 + 1] = c.g;
        colors[i * 3 + 2] = c.b;
    }

    geometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));
}

/**
 * @param {Array<Array<number>>} ring
 * @returns {[number, number]}
 */
function ringCentroid(ring) {
    let x = 0;
    let z = 0;

    for (const point of ring) {
        x += point[0];
        z += point[1];
    }

    return [x / ring.length, z / ring.length];
}

/**
 * Височината на трасето най-близо до дадена точка.
 *
 * Груб скан през всяка десета точка: ориентирите се разполагат веднъж при
 * зареждане, а на стотици метри разстояние по-голяма точност е излишна.
 *
 * @param {import('./track.js').Track} track
 * @param {number} x
 * @param {number} z
 * @returns {number}
 */
function groundHeightNear(track, x, z) {
    const { xs, ys, zs, count } = track;

    let best = 0;
    let bestDistSq = Infinity;

    for (let i = 0; i < count; i += 10) {
        const dx = x - xs[i];
        const dz = z - zs[i];
        const distSq = dx * dx + dz * dz;

        if (distSq < bestDistSq) {
            bestDistSq = distSq;
            best = ys[i];
        }
    }

    return best - Math.sqrt(bestDistSq) * RUNOFF_DROP;
}

/**
 * Лента, следваща осевата линия между две странични отмествания.
 *
 * Отместванията са в метри спрямо осевата линия, положително наляво спрямо
 * посоката на движение. Височината идва от профила на трасето; `drop` спуска
 * ръбовете пропорционално на отдалечеността им.
 *
 * @param {import('./track.js').Track} track
 * @param {number} fromOffset
 * @param {number} toOffset
 * @param {number} y
 * @param {{color: number, variation?: number, drop?: number}} options
 * @returns {THREE.Mesh}
 */
function ribbonMesh(track, fromOffset, toOffset, y, options) {
    const { xs, ys, zs, nx, nz, count, spacing } = track;

    // +1 ред върхове: последният дублира първия, за да се затвори цикълът с
    // коректни UV координати (иначе последният сегмент опъва текстурата назад).
    const rows = count + 1;
    const positions = new Float32Array(rows * 2 * 3);
    const uvs = new Float32Array(rows * 2 * 2);
    const colors = new Float32Array(rows * 2 * 3);
    const indices = new Uint32Array(count * 6);

    const base = new THREE.Color(options.color);
    const variation = options.variation ?? 0;
    const drop = options.drop ?? 0;

    for (let r = 0; r < rows; r++) {
        const i = r % count;
        const v = (r * spacing) / 8;

        for (let side = 0; side < 2; side++) {
            const offset = side === 0 ? fromOffset : toOffset;
            const vi = (r * 2 + side) * 3;

            positions[vi] = xs[i] + nx[i] * offset;
            positions[vi + 1] = ys[i] + y - Math.abs(offset) * drop;
            positions[vi + 2] = zs[i] + nz[i] * offset;

            const uvi = (r * 2 + side) * 2;
            uvs[uvi] = side;
            uvs[uvi + 1] = v;

            // Детерминиран псевдошум по индекс: чупи плоскостта на плътния
            // цвят, без да въвежда текстура (и без Math.random, което би
            // мъждукало при всяко презареждане).
            const noise = variation > 0 ? (hashNoise(i * 2 + side) - 0.5) * variation : 0;
            colors[vi] = clamp01(base.r + noise);
            colors[vi + 1] = clamp01(base.g + noise);
            colors[vi + 2] = clamp01(base.b + noise);
        }
    }

    for (let i = 0; i < count; i++) {
        const a = i * 2;
        const t = i * 6;

        indices[t] = a;
        indices[t + 1] = a + 1;
        indices[t + 2] = a + 2;
        indices[t + 3] = a + 1;
        indices[t + 4] = a + 3;
        indices[t + 5] = a + 2;
    }

    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    geometry.setAttribute('uv', new THREE.BufferAttribute(uvs, 2));
    geometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));
    geometry.setIndex(new THREE.BufferAttribute(indices, 1));

    // Върху наклонено трасе нормалите вече не сочат нагоре — от тях зависи
    // дали склонът ще се вижда като склон, или като плоско петно.
    geometry.computeVertexNormals();
    geometry.computeBoundingSphere();

    const mesh = new THREE.Mesh(
        geometry,
        new THREE.MeshLambertMaterial({ vertexColors: true })
    );
    mesh.frustumCulled = false;

    return mesh;
}

/**
 * Кербове в завоите, редуващи се червено/бяло.
 *
 * Всички блокчета влизат в един mesh — иначе завой с 40 блокчета би струвал
 * 40 draw call-а.
 *
 * @param {import('./track.js').Track} track
 * @returns {THREE.Mesh}
 */
function buildKerbs(track) {
    const { xs, ys, zs, nx, nz, count, spacing, width } = track;
    const half = width / 2;
    const ranges = findKerbRanges(track);

    const positions = [];
    const colors = [];
    const indices = [];

    const red = new THREE.Color(COLORS.kerbRed);
    const white = new THREE.Color(COLORS.kerbWhite);
    const blockSteps = Math.max(1, Math.round(KERB_BLOCK / spacing));

    for (const range of ranges) {
        for (let r = range.from; r < range.to; r++) {
            const i0 = ((r % count) + count) % count;
            const i1 = (((r + 1) % count) + count) % count;

            // Кербът е от вътрешната страна на завоя: при ляв завой (side=+1)
            // вътрешната страна е лявата, т.е. по посока на нормалата.
            const inner = range.side > 0 ? half : -half;
            const outer = range.side > 0 ? half + KERB_WIDTH : -half - KERB_WIDTH;

            const colour = Math.floor((r - range.from) / blockSteps) % 2 === 0 ? red : white;
            const vertexBase = positions.length / 3;

            for (const [idx, offset] of [
                [i0, inner],
                [i0, outer],
                [i1, inner],
                [i1, outer],
            ]) {
                positions.push(
                    xs[idx] + nx[idx] * offset,
                    ys[idx] + Y.kerb,
                    zs[idx] + nz[idx] * offset
                );
                colors.push(colour.r, colour.g, colour.b);
            }

            // Винтингът се обръща според страната — иначе кербовете отдясно
            // сочат надолу и изчезват при backface culling.
            if (range.side > 0) {
                indices.push(
                    vertexBase, vertexBase + 2, vertexBase + 1,
                    vertexBase + 1, vertexBase + 2, vertexBase + 3
                );
            } else {
                indices.push(
                    vertexBase, vertexBase + 1, vertexBase + 2,
                    vertexBase + 1, vertexBase + 3, vertexBase + 2
                );
            }
        }
    }

    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
    geometry.setAttribute('color', new THREE.Float32BufferAttribute(colors, 3));
    geometry.setIndex(indices);
    geometry.computeVertexNormals();
    geometry.computeBoundingSphere();

    const mesh = new THREE.Mesh(
        geometry,
        new THREE.MeshLambertMaterial({ vertexColors: true })
    );
    mesh.frustumCulled = false;

    return mesh;
}

/**
 * Стартово-финалната линия, напречно на трасето.
 *
 * @param {import('./track.js').Track} track
 * @returns {THREE.Mesh}
 */
function buildStartLine(track) {
    const { xs, ys, zs, nx, nz, tx, tz, width, spacing } = track;
    const half = width / 2;
    const depth = Math.max(0.6, spacing * 0.4);

    const positions = [];

    for (const [along, side] of [
        [-depth, -half],
        [-depth, half],
        [depth, -half],
        [depth, half],
    ]) {
        positions.push(
            xs[0] + nx[0] * side + tx[0] * along,
            ys[0] + Y.startLine,
            zs[0] + nz[0] * side + tz[0] * along
        );
    }

    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
    geometry.setIndex([0, 1, 2, 1, 3, 2]);
    geometry.computeVertexNormals();

    return new THREE.Mesh(
        geometry,
        new THREE.MeshBasicMaterial({ color: COLORS.startLine })
    );
}

/**
 * Стълбчета встрани от трасето.
 *
 * Чисто визуални, но носят усещането за скорост: без периферни обекти, които
 * прелитат, плоският асфалт не дава референция колко бързо се движиш.
 *
 * @param {import('./track.js').Track} track
 * @returns {THREE.InstancedMesh}
 */
function buildDistanceMarkers(track) {
    const { xs, ys, zs, nx, nz, count, spacing, width } = track;
    const half = width / 2 + 2.5;

    const every = Math.max(1, Math.round(25 / spacing));
    const capacity = Math.floor(count / every) * 2;

    const geometry = new THREE.BoxGeometry(0.25, 1.1, 0.25);
    const material = new THREE.MeshLambertMaterial({ vertexColors: true });
    const mesh = new THREE.InstancedMesh(geometry, material, capacity);

    const matrix = new THREE.Matrix4();
    const colour = new THREE.Color();
    let instance = 0;

    for (let i = 0; i < count; i += every) {
        if (instance + 1 >= capacity) {
            break;
        }

        for (const side of [1, -1]) {
            matrix.setPosition(
                xs[i] + nx[i] * half * side,
                ys[i] + 0.55 - half * RUNOFF_DROP,
                zs[i] + nz[i] * half * side
            );
            mesh.setMatrixAt(instance, matrix);

            colour.set(instance % 4 < 2 ? COLORS.marker : COLORS.markerAlt);
            mesh.setColorAt(instance, colour);

            instance++;
        }
    }

    mesh.count = instance;
    mesh.instanceMatrix.needsUpdate = true;
    if (mesh.instanceColor) {
        mesh.instanceColor.needsUpdate = true;
    }
    mesh.frustumCulled = false;

    return mesh;
}

/**
 * Земята под всичко — покрива хоризонта отвъд тревната зона.
 *
 * @param {import('./track.js').Track} track
 * @returns {THREE.Mesh}
 */
function buildGround(track) {
    const { xs, ys, zs, count } = track;

    let minX = Infinity;
    let maxX = -Infinity;
    let minZ = Infinity;
    let maxZ = -Infinity;
    let minY = Infinity;

    for (let i = 0; i < count; i++) {
        minX = Math.min(minX, xs[i]);
        maxX = Math.max(maxX, xs[i]);
        minZ = Math.min(minZ, zs[i]);
        maxZ = Math.max(maxZ, zs[i]);
        minY = Math.min(minY, ys[i]);
    }

    const size = Math.max(maxX - minX, maxZ - minZ) + 4000;
    const geometry = new THREE.PlaneGeometry(size, size);
    geometry.rotateX(-Math.PI / 2);

    const mesh = new THREE.Mesh(
        geometry,
        new THREE.MeshLambertMaterial({ color: COLORS.ground })
    );

    // Под най-ниската точка на трасето и под спуснатия ръб на тревата.
    mesh.position.set(
        (minX + maxX) / 2,
        minY - RUNOFF_WIDTH * RUNOFF_DROP - 0.5,
        (minZ + maxZ) / 2
    );

    return mesh;
}

/**
 * Детерминиран шум в [0,1) от цяло число.
 *
 * @param {number} n
 * @returns {number}
 */
function hashNoise(n) {
    const x = Math.sin(n * 12.9898) * 43758.5453;

    return x - Math.floor(x);
}

/**
 * @param {number} v
 * @returns {number}
 */
function clamp01(v) {
    return v < 0 ? 0 : v > 1 ? 1 : v;
}
