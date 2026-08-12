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

/** Височина на външния ръб на керба (вътрешният е на нивото на трасето) — 3D релеф. */
const KERB_HEIGHT = 0.07;

/** Асфалтова текстура: повторения напречно (по ширината на трасето). */
const ASPHALT_REPEAT_U = 6;

/** Асфалтова текстура: повторения по дължина на всеки 8 m (v-единицата на лентата). */
const ASPHALT_REPEAT_V = 4;

/** Тревна текстура: повторения напречно и по дължина (виж асфалта). */
const GRASS_REPEAT_U = 8;
const GRASS_REPEAT_V = 3;

/** Дължина на едно червено/бяло блокче на керба, метри. */
const KERB_BLOCK = 2.0;

/**
 * Колко навън се простира тревната лента от ръба на трасето, метри. Смъкнато
 * от 60: при компактни писти с денивелация (Монако — 55 m, Casino над
 * пристанището) широкият apron от по-високия сегмент увисваше над по-ниския и
 * скриваше пистата. Далечната трева я поема ground plane-ът (същия зелен цвят).
 */
const RUNOFF_WIDTH = 8;

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
export function buildTrackMeshes(track, assets = null) {
    const group = new THREE.Group();
    const half = track.width / 2;

    group.add(buildGround(track));
    group.add(
        ribbonMesh(track, -(half + RUNOFF_WIDTH), half + RUNOFF_WIDTH, Y.grass, {
            color: COLORS.grass,
            variation: 0.1,
            drop: RUNOFF_DROP,
            maps: assets?.grass,
            repeat: [GRASS_REPEAT_U, GRASS_REPEAT_V],
        })
    );
    group.add(
        ribbonMesh(track, -half, half, Y.asphalt, {
            color: COLORS.asphalt,
            variation: 0.06,
            maps: assets?.asphalt,
            repeat: [ASPHALT_REPEAT_U, ASPHALT_REPEAT_V],
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

    group.add(buildStartGrandstands(track));

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

    // Изхвърляме сградите, които попадат върху/до трасето — в град като Монако
    // OSM има footprint-и точно на пистата (бежевите блокове през асфалта).
    const buildings = extrudeRings(
        track,
        (landmarks.buildings ?? []).filter((ring) => !overlapsTrack(track, ring, track.width / 2 + 3)),
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
 * Дали контур (сграда) попада на по-малко от `clearance` метра от осевата линия
 * — т.е. върху/до трасето. Проверката е по върхове през всяка 2-ра осева точка;
 * еднократна е (при билд на mesh-а), не в кадъра.
 *
 * @param {import('./track.js').Track} track
 * @param {Array<[number, number]>} ring
 * @param {number} clearance
 * @returns {boolean}
 */
function overlapsTrack(track, ring, clearance) {
    const { xs, zs, count } = track;
    const c2 = clearance * clearance;
    const n = ring.length;

    // Проверяваме РЪБОВЕТЕ, не само върховете: дълга стена (напр. по средата на
    // Монако) има върхове далеч от трасето, но ръбът ѝ го пресича — само по
    // върхове минаваше през филтъра и оставаше „сляпа" бежева стена на пистата.
    for (let j = 0; j < n; j++) {
        const [ax, az] = ring[j];
        const [bx, bz] = ring[(j + 1) % n];

        for (let i = 0; i < count; i += 2) {
            if (distToSegmentSq(xs[i], zs[i], ax, az, bx, bz) < c2) {
                return true;
            }
        }
    }

    return false;
}

/** Квадрат на разстоянието от точка (px,pz) до отсечка (ax,az)-(bx,bz). */
function distToSegmentSq(px, pz, ax, az, bx, bz) {
    const dx = bx - ax;
    const dz = bz - az;
    const lenSq = dx * dx + dz * dz;

    let t = lenSq > 0 ? ((px - ax) * dx + (pz - az) * dz) / lenSq : 0;
    t = t < 0 ? 0 : t > 1 ? 1 : t;

    const ex = px - (ax + t * dx);
    const ez = pz - (az + t * dz);

    return ex * ex + ez * ez;
}

/**
 * Процедурни трибуни покрай стартовата права. Реалните OSM ориентири са малко
 * (по няколко на писта), а всяка писта има главна трибуна на старт/финала. Това
 * добавя разпознаваема „стадион" атмосфера, без да зависи от пълнотата на OSM.
 *
 * Кутиите пазят frustum culling (за разлика от лентите) — изнасят се от кадъра
 * зад гърба на колата.
 *
 * @param {import('./track.js').Track} track
 * @returns {THREE.Group}
 */
function buildStartGrandstands(track) {
    const { xs, ys, zs, nx, nz, tx, tz, count, spacing, width } = track;
    const group = new THREE.Group();
    const half = width / 2;

    const GAP = 7; // отстъп навън от ръба на асфалта, m
    const DEPTH = 18; // дълбочина навън, m
    const HEIGHT = 12; // височина, m
    const SECTION = 26; // дължина на секция по трасето, m
    const SPAN = 165; // колко от правата да покрием, m

    const sections = Math.max(3, Math.round(SPAN / SECTION));
    const step = Math.max(1, Math.round(SECTION / spacing));
    const startBack = Math.round(20 / spacing); // започва ~20 m преди старта

    // Процедурна „публика" — хиляди цветни точки върху тъмни седалки → пълни
    // трибуни без external asset. Строи се веднъж, споделя се от всички секции.
    const crowd = makeCrowdTexture();
    crowd.repeat.set(6, 3);
    const bodyMat = new THREE.MeshStandardMaterial({ map: crowd, color: 0xffffff, metalness: 0.0, roughness: 0.9 });
    const roofMat = new THREE.MeshStandardMaterial({ color: COLORS.grandstandRoof, metalness: 0.45, roughness: 0.5 });

    // Рекламни бордове пред трибуните — iconic за F1, носят и цвят на сцената.
    const hoarding = makeHoardingTexture();
    hoarding.repeat.set(3, 1);
    const hoardMat = new THREE.MeshStandardMaterial({ map: hoarding, metalness: 0.1, roughness: 0.6 });
    const hoardGeo = new THREE.BoxGeometry(0.3, 1.3, SECTION * 0.95);

    // Наклонена седяща банка вместо блокче: свалям горния ръб откъм трасето, за
    // да се вдига навън, както истинска трибуна. (+X сочи към трасето след
    // rotation.y; ако ракът излезе обърнат, се сменя знакът на `front`.)
    const bodyGeo = new THREE.BoxGeometry(DEPTH, HEIGHT, SECTION * 0.9);
    {
        const p = bodyGeo.attributes.position;
        for (let v = 0; v < p.count; v++) {
            if (p.getY(v) > 0) {
                const front = (p.getX(v) + DEPTH / 2) / DEPTH; // 1 откъм трасето, 0 отзад
                p.setY(v, p.getY(v) - HEIGHT * 0.6 * front);
            }
        }
        bodyGeo.computeVertexNormals();
    }

    const roofGeo = new THREE.BoxGeometry(DEPTH * 0.92, 0.4, SECTION * 0.96);

    for (const sign of [1, -1]) {
        for (let s = 0; s < sections; s++) {
            const i = (((s * step - startBack) % count) + count) % count;
            const angle = Math.atan2(tx[i], tz[i]);
            const off = sign * (half + GAP + DEPTH / 2);

            const body = new THREE.Mesh(bodyGeo, bodyMat);
            body.position.set(xs[i] + nx[i] * off, ys[i] + HEIGHT / 2, zs[i] + nz[i] * off);
            body.rotation.y = angle;
            group.add(body);

            // Покрив над задната (високата) част, надвиснал над седалките.
            const roof = new THREE.Mesh(roofGeo, roofMat);
            roof.position.set(xs[i] + nx[i] * off, ys[i] + HEIGHT + 0.4, zs[i] + nz[i] * off);
            roof.rotation.y = angle;
            group.add(roof);

            // Рекламен борд пред трибуната, на нивото на пистата.
            const hoardOff = sign * (half + GAP * 0.4);
            const hoard = new THREE.Mesh(hoardGeo, hoardMat);
            hoard.position.set(xs[i] + nx[i] * hoardOff, ys[i] + 0.65, zs[i] + nz[i] * hoardOff);
            hoard.rotation.y = angle;
            group.add(hoard);
        }
    }

    return group;
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

    const mesh = new THREE.Mesh(merged, new THREE.MeshStandardMaterial({ color, metalness: 0.1, roughness: 0.75 }));
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
        new THREE.MeshStandardMaterial({ vertexColors: true, metalness: 0, roughness: 0.9 }),
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
    const { xs, ys, zs, nx, nz, count, spacing, curvature } = track;

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

        // Радиусът на завоя е 1/κ. Лента, по-широка от радиуса, се сгъва навътре
        // отвъд центъра на кривината и прави каша (Монако Fairmont ~8 м, тесните
        // завои на Спа). Ограничаваме отместването до 80% от радиуса, само от
        // ВЪТРЕШНАТА (вдлъбната) страна — външната не се сгъва.
        const k = curvature[i];
        const innerLimit = k !== 0 ? (0.5 / k) : 0;

        for (let side = 0; side < 2; side++) {
            let offset = side === 0 ? fromOffset : toOffset;
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

    let material;
    if (options.maps && options.maps.map) {
        // Реална tiling PBR текстура (напр. асфалт). vertexColors се изключва —
        // цветът идва от картата, не от плоския базов цвят.
        const m = options.maps;
        const [ru, rv] = options.repeat ?? [1, 1];
        for (const texture of [m.map, m.normalMap, m.roughnessMap]) {
            if (texture) {
                texture.repeat.set(ru, rv);
            }
        }
        material = new THREE.MeshStandardMaterial({
            map: m.map,
            normalMap: m.normalMap ?? null,
            roughnessMap: m.roughnessMap ?? null,
            metalness: 0,
            roughness: 1,
        });
    } else {
        material = new THREE.MeshStandardMaterial({ vertexColors: true, metalness: 0, roughness: 0.9 });
    }

    const mesh = new THREE.Mesh(geometry, material);
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

            // Вътрешният ръб е на нивото на трасето, външният — издигнат: кербът
            // става наклонена 3D лента (rumble strip), не плоско петно.
            for (const [idx, offset, h] of [
                [i0, inner, Y.kerb],
                [i0, outer, KERB_HEIGHT],
                [i1, inner, Y.kerb],
                [i1, outer, KERB_HEIGHT],
            ]) {
                positions.push(
                    xs[idx] + nx[idx] * offset,
                    ys[idx] + h,
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
        // По-гланцов от асфалта — боядисаните кербове ловят HDRI-то (мокър вид).
        new THREE.MeshStandardMaterial({ vertexColors: true, metalness: 0.1, roughness: 0.5 })
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
    const material = new THREE.MeshStandardMaterial({ vertexColors: true, metalness: 0, roughness: 0.9 });
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
        new THREE.MeshStandardMaterial({ color: COLORS.ground, metalness: 0, roughness: 1 })
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
 * Процедурна текстура на публика: тъмни седалки + хиляди дребни цветни точки
 * (зрители). Детерминирана (hashNoise, не Math.random) — без мъждукане при
 * презареждане. Строи се веднъж и се tiling-ва по трибуните.
 *
 * @returns {THREE.CanvasTexture}
 */
function makeCrowdTexture() {
    const size = 256;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');

    ctx.fillStyle = '#23272e';
    ctx.fillRect(0, 0, size, size);

    const colors = ['#d94f4f', '#e6e6e6', '#4f7fd9', '#e0c24f', '#5ad07a', '#c94fd9', '#d98a4f', '#ffffff', '#4a4f57', '#f0f0f0'];
    for (let i = 0; i < 4600; i++) {
        ctx.fillStyle = colors[Math.floor(hashNoise(i * 1.37) * colors.length)];
        ctx.fillRect(hashNoise(i * 2.11) * size, hashNoise(i * 3.71) * size, 2, 2);
    }

    const texture = new THREE.CanvasTexture(canvas);
    texture.wrapS = THREE.RepeatWrapping;
    texture.wrapT = THREE.RepeatWrapping;
    texture.colorSpace = THREE.SRGBColorSpace;

    return texture;
}

/**
 * Процедурна текстура на рекламни бордове: цветни блокчета с бяла рамка (без
 * реални лога — генерично, IP-чисто).
 *
 * @returns {THREE.CanvasTexture}
 */
function makeHoardingTexture() {
    const w = 512;
    const h = 64;
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');

    const colors = ['#c0392b', '#2c3e50', '#16a085', '#e67e22', '#2980b9', '#8e44ad', '#f1c40f', '#ecf0f1'];
    let x = 0;
    let k = 0;
    while (x < w) {
        const bw = 44 + hashNoise(k * 5.3) * 44;
        ctx.fillStyle = colors[Math.floor(hashNoise(k * 1.7) * colors.length)];
        ctx.fillRect(x, 0, bw, h);
        ctx.strokeStyle = 'rgba(255,255,255,0.55)';
        ctx.lineWidth = 2;
        ctx.strokeRect(x + 6, 12, bw - 12, h - 24);
        x += bw;
        k++;
    }

    const texture = new THREE.CanvasTexture(canvas);
    texture.wrapS = THREE.RepeatWrapping;
    texture.wrapT = THREE.RepeatWrapping;
    texture.colorSpace = THREE.SRGBColorSpace;

    return texture;
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
