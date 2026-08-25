/**
 * Процедурно генериране на 3D геометрията на пистата.
 *
 * Нищо не се зарежда като готов модел — всичко се извежда от осевата линия,
 * височинния профил и кривината. Така всяка нова писта е един JSON файл, не
 * часове моделиране.
 */

import * as THREE from 'three';
import { mergeGeometries } from 'three/examples/jsm/utils/BufferGeometryUtils.js';
import { buildCircuitDecor, createTerrainSampler, fenceMesh } from './decor.js';
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
 * @param {import('./circuits.js').CircuitStyle} circuit Визуалната идентичност
 * @returns {THREE.Group}
 */
export function buildTrackMeshes(track, circuit) {
    const group = new THREE.Group();
    const half = track.width / 2;

    group.add(buildGround(track, circuit));

    // Асфалтът и тревата тръгват с процедурен (vertex-color) материал. Ако
    // техните PBR текстури се заредят успешно ПРЕДИ старта, Game.js ги подменя
    // (виж #loadTrackTextures); при неуспех остава процедурният цвят — никога
    // черно. Пазим материалите за тази подмяна в userData.
    const grass = ribbonMesh(track, -(half + RUNOFF_WIDTH), half + RUNOFF_WIDTH, Y.grass, {
        color: COLORS.grass,
        variation: 0.1,
        drop: RUNOFF_DROP,
    });
    const asphalt = ribbonMesh(track, -half, half, Y.asphalt, {
        color: COLORS.asphalt,
        variation: 0.06,
    });
    group.add(grass);
    group.add(asphalt);
    group.userData.surfaces = { asphalt: asphalt.material, grass: grass.material };
    group.add(
        ribbonMesh(track, half - EDGE_LINE_WIDTH, half, Y.edgeLine, { color: COLORS.edgeLine })
    );
    group.add(
        ribbonMesh(track, -half, -half + EDGE_LINE_WIDTH, Y.edgeLine, { color: COLORS.edgeLine })
    );
    group.add(buildKerbs(track));
    group.add(buildStartLine(track));

    // Декорът, който прави пистата разпознаваема: питлейн, решетка, гантри,
    // табели, чакъл, терен, специалните ориентири (виж decor.js). Семплерът на
    // терена е ОБЩ за terrain mesh-а, дърветата и сградите — една повърхност.
    const sampler = createTerrainSampler(track, circuit);
    const decor = buildCircuitDecor(track, circuit, sampler);
    group.add(decor.group);
    group.userData.startLights = decor.startLights;
    group.userData.animations = decor.animations;
    // Чакълът влиза в същата PBR подмяна като асфалта/тревата (Game).
    if (decor.gravelMaterial) {
        group.userData.surfaces.gravel = decor.gravelMaterial;
    }

    // На градска писта стълбчетата са безсмислени (стените са навсякъде), а в
    // диапазона на питовете се сблъскват с комплекса.
    if (!circuit.streetWalls) {
        group.add(buildDistanceMarkers(track, decor.pitRange));
    }

    for (const mesh of buildLandmarks(track, circuit, sampler)) {
        group.add(mesh);
    }

    if (circuit.startGrandstands) {
        group.add(buildStartGrandstands(track, circuit, decor.pitRange));
    }

    // Маршал с кариран флаг до старт/финала — flagPivot се вее от Game.#frame
    // на летящата (финална) обиколка.
    const marshal = buildMarshal(track);
    group.add(marshal.group);
    group.userData.marshalFlag = marshal.flagPivot;

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
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @param {import('./decor.js').TerrainSampler} sampler
 * @returns {THREE.Object3D[]}
 */
function buildLandmarks(track, circuit, sampler) {
    const landmarks = track.landmarks;

    if (!landmarks) {
        return [];
    }

    const out = [];

    const grandstands = extrudeRings(
        track,
        sampler,
        landmarks.grandstands ?? [],
        LANDMARK_HEIGHT.grandstand,
        COLORS.grandstand
    );
    if (grandstands) {
        out.push(grandstands);
    }

    // Изхвърляме сградите, които попадат върху/до трасето — в град като Монако
    // OSM има footprint-и точно на пистата (бежевите блокове през асфалта).
    // Височината е част от идентичността: жилищните блокове на Монако правят
    // каньона, а паддок постройките на Силвърстоун са ниски.
    const buildings = extrudeRings(
        track,
        sampler,
        (landmarks.buildings ?? []).filter((ring) => !overlapsTrack(track, ring, track.width / 2 + 3)),
        circuit.buildingHeight ?? LANDMARK_HEIGHT.building,
        COLORS.building
    );
    if (buildings) {
        out.push(buildings);
    }

    const trees = buildTrees(track, circuit, sampler, landmarks.trees ?? []);
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
 * Инстанцирани (3 draw call-а за всички секции) и без сянка — леко за телефон.
 *
 * Строят се само СРЕЩУ питовете — от другата страна стои пит комплексът
 * (decor.js), точно както главната трибуна на реална писта гледа към боксовете.
 *
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @param {{sign: number}} pitRange
 * @returns {THREE.Group}
 */
function buildStartGrandstands(track, circuit, pitRange) {
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
    const crowd = makeCrowdTexture(circuit.crowdAccent);
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

    // Инстанцирани: 3 draw call-а (тяло/покрив/борд) вместо ~36 отделни меша —
    // и толкова по-малко в сенчестия pass. Не хвърлят сянка (виж Game: само
    // колата хвърля) и не се cull-ват (3 евтини рисувания, винаги налични).
    const capacity = sections;
    const bodies = new THREE.InstancedMesh(bodyGeo, bodyMat, capacity);
    const roofs = new THREE.InstancedMesh(roofGeo, roofMat, capacity);
    const hoards = new THREE.InstancedMesh(hoardGeo, hoardMat, capacity);

    const matrix = new THREE.Matrix4();
    const position = new THREE.Vector3();
    const quaternion = new THREE.Quaternion();
    const scale = new THREE.Vector3(1, 1, 1);
    let n = 0;

    for (const sign of [-pitRange.sign]) {
        for (let s = 0; s < sections; s++) {
            const i = (((s * step - startBack) % count) + count) % count;
            // Наклонената банка гледа към +X в локални координати; от лявата
            // страна (sign<0) секцията се обръща на 180°, за да гледа трасето.
            quaternion.setFromAxisAngle(UP, Math.atan2(tx[i], tz[i]) + (sign < 0 ? Math.PI : 0));
            const off = sign * (half + GAP + DEPTH / 2);

            position.set(xs[i] + nx[i] * off, ys[i] + HEIGHT / 2, zs[i] + nz[i] * off);
            bodies.setMatrixAt(n, matrix.compose(position, quaternion, scale));

            // Покрив над задната (високата) част, надвиснал над седалките.
            position.set(xs[i] + nx[i] * off, ys[i] + HEIGHT + 0.4, zs[i] + nz[i] * off);
            roofs.setMatrixAt(n, matrix.compose(position, quaternion, scale));

            // Рекламен борд пред трибуната, на нивото на пистата.
            const hoardOff = sign * (half + GAP * 0.4);
            position.set(xs[i] + nx[i] * hoardOff, ys[i] + 0.65, zs[i] + nz[i] * hoardOff);
            hoards.setMatrixAt(n, matrix.compose(position, quaternion, scale));

            n++;
        }
    }

    for (const mesh of [bodies, roofs, hoards]) {
        mesh.count = n;
        mesh.instanceMatrix.needsUpdate = true;
        mesh.castShadow = false;
        mesh.frustumCulled = false;
        group.add(mesh);
    }

    // Защитна ограда между трасето и трибуните — по целия им фронт. Долният
    // ръб слиза под нивото на спуснатата трева (иначе виси на ~половин метър).
    const fenceSign = -pitRange.sign;
    group.add(
        fenceMesh(
            track,
            -startBack,
            -startBack + sections * step,
            fenceSign * (half + 2.0),
            -0.8,
            3.2
        )
    );

    return group;
}

/**
 * Издига контурите в обеми и ги слива в един mesh.
 *
 * Сливането не е разкош: 160 отделни сгради са 160 draw call-а и сами по себе
 * си свалят кадрите на телефон под играбилното.
 *
 * @param {import('./track.js').Track} track
 * @param {import('./decor.js').TerrainSampler} sampler
 * @param {Array<Array<Array<number>>>} rings
 * @param {number} baseHeight
 * @param {number} color
 * @returns {THREE.Mesh|null}
 */
function extrudeRings(track, sampler, rings, baseHeight, color) {
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

        // Сядат върху общия терен (семплера) — същата мрежа рендерира и
        // релефа, така че сграда на хълм стои НА хълма, не виси до него.
        // Лекият минус компенсира наклона на терена под широк контур.
        const centroid = ringCentroid(ring);
        geometry.translate(0, sampler.heightAt(centroid[0], centroid[1]) - 0.4, 0);

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
 * Дървета в горските зони — силуетът зависи от пистата: смърчове в Ардените
 * и Щирия, широколистни в кралския парк на Монца, ниски храсти по дюните на
 * Зандвоорт. 'mixed' редува двата вида детерминирано.
 *
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @param {import('./decor.js').TerrainSampler} sampler
 * @param {Array<Array<number>>} trees
 * @returns {THREE.Group|null}
 */
function buildTrees(track, circuit, sampler, trees) {
    if (trees.length === 0) {
        return null;
    }

    const kindFor = (seed) => {
        if (circuit.trees === 'mixed') {
            return hashNoise(seed * 7.91) > 0.45 ? 'deciduous' : 'conifer';
        }
        return circuit.trees;
    };

    // Сгъстяване на гората: OSM дава по едно дърво на площ, а Монца и Спа са
    // тунели от зеленина. При density > 1 всяко дърво получава клонинги,
    // разхвърляни детерминирано около него.
    //
    // Всяко разположение (и клонинг, и оригинал) се проверява срещу трасето —
    // клонинг на 5–18 m от дърво до банкета иначе стъпва на асфалта или в
    // чакъла. Клирънсът покрива и run-off зоната; стъпка 2 реда (8 m), за да
    // не пропусне точка между два семпъла.
    const clearance = track.width / 2 + 9;
    const clearanceSq = clearance * clearance;
    const clearOfTrack = (x, z) => {
        const { xs, zs, count } = track;
        for (let i = 0; i < count; i += 2) {
            const dx = x - xs[i];
            const dz = z - zs[i];
            if (dx * dx + dz * dz < clearanceSq) {
                return false;
            }
        }
        return true;
    };

    const density = circuit.treeDensity ?? 1;
    const placements = [];
    for (let i = 0; i < trees.length && placements.length < 2600; i++) {
        const [x, z, s] = trees[i];
        if (clearOfTrack(x, z)) {
            placements.push([x, z, s, i]);
        }

        const extra = Math.floor(density - 1 + hashNoise(i * 13.7));
        for (let e = 0; e < extra && placements.length < 2600; e++) {
            const angle = hashNoise(i * 17.3 + e * 7.1) * Math.PI * 2;
            const radius = 5 + hashNoise(i * 23.9 + e * 3.3) * 13;
            const cx = x + Math.cos(angle) * radius;
            const cz = z + Math.sin(angle) * radius;
            if (clearOfTrack(cx, cz)) {
                placements.push([cx, cz, s * (0.8 + hashNoise(i + e * 41.7) * 0.5), i * 31 + e + 1009]);
            }
        }
    }

    // Разпределяме инстанциите по вид: до два InstancedMesh-а на писта.
    const buckets = new Map();
    for (let p = 0; p < placements.length; p++) {
        const kind = kindFor(placements[p][3]);
        if (!buckets.has(kind)) {
            buckets.set(kind, []);
        }
        buckets.get(kind).push(p);
    }

    const group = new THREE.Group();
    const matrix = new THREE.Matrix4();
    const position = new THREE.Vector3();
    const quaternion = new THREE.Quaternion();
    const scale = new THREE.Vector3();

    for (const [kind, indices] of buckets) {
        const geometry = treeGeometry(kind, circuit.foliage);

        if (!geometry) {
            continue;
        }

        const mesh = new THREE.InstancedMesh(
            geometry,
            new THREE.MeshStandardMaterial({ vertexColors: true, metalness: 0, roughness: 0.9 }),
            indices.length
        );

        for (let n = 0; n < indices.length; n++) {
            const [x, z, s, seed] = placements[indices[n]];

            position.set(x, sampler.heightAt(x, z) - 0.2, z);
            quaternion.setFromAxisAngle(UP, hashNoise(seed) * Math.PI * 2);
            scale.set(s, s * (0.85 + hashNoise(seed * 3) * 0.4), s);

            matrix.compose(position, quaternion, scale);
            mesh.setMatrixAt(n, matrix);
        }

        mesh.instanceMatrix.needsUpdate = true;
        mesh.frustumCulled = false;
        group.add(mesh);
    }

    return group.children.length > 0 ? group : null;
}

/**
 * Геометрията на едно дърво от даден вид, с боядисани върхове.
 *
 * @param {'conifer'|'deciduous'|'shrub'} kind
 * @param {number} foliageColor
 * @returns {THREE.BufferGeometry|null}
 */
function treeGeometry(kind, foliageColor) {
    // mergeGeometries отказва микс от indexed (цилиндър/конус) и non-indexed
    // (икосаедър) геометрии — нормализираме всичко до non-indexed.
    const parts = [];
    const add = (geometry, color) => {
        const flat = geometry.index ? geometry.toNonIndexed() : geometry;
        if (flat !== geometry) {
            geometry.dispose();
        }
        paintGeometry(flat, color);
        parts.push(flat);
    };

    if (kind === 'shrub') {
        // Нисък крайбрежен храст — без стъбло, сплескана топка.
        const bush = new THREE.IcosahedronGeometry(1.5, 0);
        bush.scale(1, 0.62, 1);
        bush.translate(0, 0.85, 0);
        add(bush, foliageColor);
    } else if (kind === 'deciduous') {
        const trunk = new THREE.CylinderGeometry(0.26, 0.34, 2.6, 5);
        trunk.translate(0, 1.3, 0);
        add(trunk, COLORS.trunk);

        const crown = new THREE.IcosahedronGeometry(2.6, 0);
        crown.translate(0, 4.6, 0);
        add(crown, foliageColor);

        const crownTop = new THREE.IcosahedronGeometry(1.7, 0);
        crownTop.translate(0.7, 6.2, 0.3);
        add(crownTop, foliageColor);
    } else {
        const trunk = new THREE.CylinderGeometry(0.22, 0.3, 2.4, 5);
        trunk.translate(0, 1.2, 0);
        add(trunk, COLORS.trunk);

        const cone = new THREE.ConeGeometry(2.1, 6.5, 6);
        cone.translate(0, 5.6, 0);
        add(cone, foliageColor);
    }

    const geometry = mergeGeometries(parts, false);
    for (const part of parts) {
        part.dispose();
    }

    return geometry;
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

    const mesh = new THREE.Mesh(
        geometry,
        new THREE.MeshStandardMaterial({ vertexColors: true, metalness: 0, roughness: 0.9 })
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

            // Кербът е от вътрешната страна на завоя: при завой към нормалата
            // (side=+1) вътрешната страна е тази на нормалата.
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

            // Винтингът се обръща според страната — при side>0 отместванията
            // растат (нагоре по нормалата), при side<0 намаляват. Двата бранша
            // бяха разменени и ВСИЧКИ кербове гледаха надолу (изчезваха при
            // backface culling) — правилно е растящи отмествания → прав ред.
            if (range.side > 0) {
                indices.push(
                    vertexBase, vertexBase + 1, vertexBase + 2,
                    vertexBase + 1, vertexBase + 3, vertexBase + 2
                );
            } else {
                indices.push(
                    vertexBase, vertexBase + 2, vertexBase + 1,
                    vertexBase + 1, vertexBase + 2, vertexBase + 3
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
 * @param {{from: number, to: number, sign: number}} pitRange Пропуска се пит зоната
 * @returns {THREE.InstancedMesh}
 */
function buildDistanceMarkers(track, pitRange) {
    const { xs, ys, zs, nx, nz, count, spacing, width } = track;
    const half = width / 2 + 2.5;

    const every = Math.max(1, Math.round(25 / spacing));
    const capacity = Math.floor(count / every) * 2;

    // Пит зоната в увити редове: [from..to] спрямо ред 0 може да е отрицателно.
    const inPit = (i) => {
        const wrapped = i > count / 2 ? i - count : i;
        return wrapped > pitRange.from && wrapped < pitRange.to;
    };

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
            // На страната на питовете стълбчето би стояло в питлейна.
            if (inPit(i) && side === pitRange.sign) {
                continue;
            }

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
 * Земята под всичко — покрива хоризонта отвъд терена (decor.js), в цвета на
 * основната палитра на пистата.
 *
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @returns {THREE.Mesh}
 */
function buildGround(track, circuit) {
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

    const ground = new THREE.Color(circuit.terrain.base).multiplyScalar(0.72);
    const mesh = new THREE.Mesh(
        geometry,
        new THREE.MeshStandardMaterial({ color: ground, metalness: 0, roughness: 1 })
    );

    // Под най-ниската точка на трасето, спуснатия ръб на тревата И най-ниските
    // падини на терена (релефът слиза до ~30% от амплитудата под нулата).
    mesh.position.set(
        (minX + maxX) / 2,
        minY - RUNOFF_WIDTH * RUNOFF_DROP - 1.4 - circuit.terrain.amplitude * 0.35,
        (minZ + maxZ) / 2
    );

    return mesh;
}

/**
 * Маршал на банкета до стартовата линия. Вее кариран флаг на финалната
 * (летящата) обиколка — анимира се от Game.#frame чрез върнатия `flagPivot`.
 *
 * Процедурен, low-poly (като дърветата) — без външен модел. Един е, затова
 * няколкото меша са без значение (локален, frustum-culled).
 *
 * @param {import('./track.js').Track} track
 * @returns {{group: THREE.Group, flagPivot: THREE.Group}}
 */
function buildMarshal(track) {
    const group = new THREE.Group();

    const hiVis = new THREE.MeshStandardMaterial({ color: 0xff7a1a, roughness: 0.6 }); // оранжев елек
    const skin = new THREE.MeshStandardMaterial({ color: 0xe0a884, roughness: 0.75 });
    const dark = new THREE.MeshStandardMaterial({ color: 0x24272c, roughness: 0.8 });

    const legs = new THREE.Mesh(new THREE.BoxGeometry(0.34, 0.8, 0.26), dark);
    legs.position.y = 0.4;
    group.add(legs);

    const torso = new THREE.Mesh(new THREE.BoxGeometry(0.42, 0.6, 0.28), hiVis);
    torso.position.y = 1.05;
    group.add(torso);

    const head = new THREE.Mesh(new THREE.SphereGeometry(0.13, 12, 10), skin);
    head.position.y = 1.5;
    group.add(head);

    // Вдигната ръка към флага.
    const arm = new THREE.Mesh(new THREE.BoxGeometry(0.12, 0.52, 0.12), hiVis);
    arm.position.set(0.32, 1.28, 0);
    arm.rotation.z = -0.7;
    group.add(arm);

    // ── Флаг на прът — pivot при дланта, върти се за „веене" ──
    const flagPivot = new THREE.Group();
    flagPivot.position.set(0.52, 1.5, 0);

    const pole = new THREE.Mesh(new THREE.CylinderGeometry(0.02, 0.02, 0.9, 6), dark);
    pole.position.y = 0.35;
    flagPivot.add(pole);

    const flagGeo = new THREE.PlaneGeometry(0.7, 0.45);
    flagGeo.translate(0.35, 0, 0); // виси от пръта надясно
    const flag = new THREE.Mesh(
        flagGeo,
        new THREE.MeshStandardMaterial({ map: makeCheckeredTexture(), side: THREE.DoubleSide, roughness: 0.9 })
    );
    flag.position.y = 0.62;
    flagPivot.add(flag);

    group.add(flagPivot);

    // Точно до ръба на трасето (пред рекламния борд), малко след старт/финалната
    // линия, с лице към трасето — да се вижда ясно от минаващия болид.
    const i = Math.round(12 / track.spacing) % track.count;
    const off = track.width / 2 + 1.4;
    group.position.set(
        track.xs[i] + track.nx[i] * off,
        track.ys[i],
        track.zs[i] + track.nz[i] * off
    );
    group.rotation.y = Math.atan2(-track.nx[i], -track.nz[i]);

    return { group, flagPivot };
}

/**
 * Кариран флаг — 8×8 черно/бяло каре. Строи се веднъж.
 *
 * @returns {THREE.CanvasTexture}
 */
function makeCheckeredTexture() {
    const size = 128;
    const squares = 8;
    const cell = size / squares;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');

    for (let y = 0; y < squares; y++) {
        for (let x = 0; x < squares; x++) {
            ctx.fillStyle = (x + y) % 2 === 0 ? '#0a0a0a' : '#f4f4f4';
            ctx.fillRect(x * cell, y * cell, cell, cell);
        }
    }

    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.SRGBColorSpace;

    return texture;
}

/**
 * Процедурна текстура на публика: тъмни седалки + хиляди дребни цветни точки
 * (зрители). Детерминирана (hashNoise, не Math.random) — без мъждукане при
 * презареждане. Строи се веднъж и се tiling-ва по трибуните.
 *
 * @param {number|null} accent Доминиращ цвят (тифозите на Монца, оранжевата
 *        армия на Зандвоорт) — около 40% от точките го получават.
 * @returns {THREE.CanvasTexture}
 */
function makeCrowdTexture(accent = null) {
    const size = 256;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');

    ctx.fillStyle = '#2c313a';
    ctx.fillRect(0, 0, size, size);

    const colors = ['#d94f4f', '#e6e6e6', '#4f7fd9', '#e0c24f', '#5ad07a', '#c94fd9', '#d98a4f', '#ffffff', '#4a4f57', '#f0f0f0'];
    const accentCss = accent === null ? null : '#' + accent.toString(16).padStart(6, '0');
    for (let i = 0; i < 4600; i++) {
        ctx.fillStyle =
            accentCss !== null && hashNoise(i * 5.13) < 0.4
                ? accentCss
                : colors[Math.floor(hashNoise(i * 1.37) * colors.length)];
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
