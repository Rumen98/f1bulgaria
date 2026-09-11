/**
 * Процедурно генериране на 3D геометрията на пистата.
 *
 * Нищо не се зарежда като готов модел — всичко се извежда от осевата линия,
 * височинния профил и кривината. Така всяка нова писта е един JSON файл, не
 * часове моделиране.
 *
 * Версия 2 на геометрията (userData.geometryVersion): пътят ляга В пейзажа —
 * банкетът тръгва от ръба на асфалта и се спуска навън (верж + пола), кербовете
 * са истински rumble strip-ове с външна стена, UV-тата са метрични (4 m тайл,
 * без шев на старт/финала), а лентите носят per-vertex атрибутите, които
 * surfaceShader.js чете (aLateral / aAlong / aHalfWidth + спомагателните).
 * Теренът, растителността и хоризонтът живеят в terrain.js / vegetation.js /
 * backdrop.js — тук само се сглобяват в една група.
 */

import * as THREE from 'three';
import { mergeGeometries } from 'three/examples/jsm/utils/BufferGeometryUtils.js';
import { buildBackdrop } from './backdrop.js';
import { buildCircuitDecor, fenceMesh } from './decor.js';
import { buildingMaterial, makeCrowdTexture } from './facades.js';
import { curvatureRanges, runoffRanges } from './sim.js';
import { buildTerrain, createTerrainSampler } from './terrain.js';
import { findKerbRanges } from './track.js';
import { buildVegetation } from './vegetation.js';

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

/**
 * Метричен UV тайл на повърхностите, метри: u = aLateral / TILE, v = aAlong /
 * TILE. Game.#loadTrackTextures слага texture.repeat (1,1) — плътността е
 * еднаква при 9 m (Монако) и 18 m (фунията на Монца), а v е цяло число на
 * всеки ред (spacing 4 m), така че обиколката се затваря без шев.
 */
export const TILE = 4;

/** Височини за extrude на ориентирите, метри. */
const LANDMARK_HEIGHT = {
    grandstand: 11.0,
    building: 7.5,
};

/** Ширина на кербовете, метри. */
const KERB_WIDTH = 1.1;

/** Височина на външния ръб на керба над платното (пикът на назъбването). */
const KERB_HEIGHT = 0.07;

/**
 * Период на назъбването (rumble strip), метри. ЕДНО число за целия проект:
 * симулацията го ползва за ритъма на удара, звукът за тракането, камерата за
 * треперенето — окото трябва да вижда същия ритъм, който тялото усеща.
 */
const KERB_PERIOD = 0.9;

/**
 * Стъпка на под-редовете на керба. Профилът е триъгълна вълна: семплирана
 * точно на връх и дъно (период/2), линейната интерполация между върховете я
 * възпроизвежда БЕЗ грешка. Старото семплиране на всеки 4 m ред алиасваше
 * 0.9 m вълна в произволна височина на ред — „трептене" вместо назъбване.
 */
const KERB_STEP = KERB_PERIOD / 2;

/** Дължина на едно червено/бяло блокче на керба, метри (FIA: 0.5–1 m). */
const KERB_BLOCK = 0.75;

/** Ширина на вътрешната устна на керба (към асфалта), метри. */
const KERB_LIP = 0.05;

/**
 * Колко навън се простира банкетът (верж) от ръба на трасето, метри. Осем, не
 * шейсет: при компактни писти с денивелация (Монако — 55 m, Casino над
 * пристанището) широкият apron от по-високия сегмент увисваше над по-ниския.
 * Отвъд него е полата, а после теренът (terrain.js).
 */
export const RUNOFF_WIDTH = 8;

/**
 * Спад на банкета на метър НАВЪН ОТ РЪБА на асфалта. Спадът се брои само за
 * разстоянието извън платното — така вержът тръгва от ръба (никакво стъпало),
 * а не е плосък диск, спуснат ~0.6 m под цялата писта.
 */
export const RUNOFF_DROP = 0.035;

/**
 * Полата отвъд вержа: по-стръмен спад, който гарантирано слиза ПОД терена.
 * Каквото и да прави terrain.js на ръба, ръбът на тревата никога не виси във
 * въздуха — той е заровен.
 */
const SKIRT_WIDTH = 5;
const SKIRT_SLOPE = 0.15;

/** „Коляното" на вержа — прашната ивица утъпкана трева до асфалта, метри. */
const SHOULDER_WIDTH = 0.35;

/** Вертикално нареждане на слоевете — предпазва от z-fighting. */
const Y = {
    grass: -0.03,
    // В run-off зоните и пред питовете вержът остава на старото ниво, за да
    // не покрие чакъла (decor.js, Y −0.10) и питлейна — те лежат ВЪРХУ него.
    grassLow: -0.12,
    asphalt: 0.0,
    kerbLip: 0.005,
    kerbInner: 0.02,
    kerbOuterBottom: -0.1,
    startLine: 0.014,
};

/**
 * Височина на банкета при странично отместване `offset` от осевата линия на
 * ред `i`: платното (в рамките на halfWidths) е на нивото на профила, навън
 * спада с RUNOFF_DROP, отвъд RUNOFF_WIDTH — с полата; банкингът накланя
 * всичко напречно. ЕДНО правило за верж, кербове, стълбчета, маршали и
 * (по договор) decor.js stripGeometry — иначе слоевете се разминават.
 *
 * @param {import('./track.js').Track} track
 * @param {number} i Индекс на реда
 * @param {number} offset Метри по нормалата (+ надясно по посоката)
 * @param {number} [y0] Слой над повърхността (виж Y)
 * @returns {number}
 */
export function vergeHeight(track, i, offset, y0 = 0) {
    const outside = Math.max(0, Math.abs(offset) - track.halfWidths[i]);
    const skirt = Math.max(0, outside - RUNOFF_WIDTH);

    return track.ys[i] + y0 - outside * RUNOFF_DROP - skirt * SKIRT_SLOPE - offset * track.bankSlope[i];
}

/**
 * @typedef {object} TrackMeshOptions
 * @property {boolean} [lowPower]  Телефон/слаба машина — без десктопските карти
 * @property {object|null} [quality] game.quality (подава се нататък към пакетите)
 * @property {object|null} [look]   circuit.look (atmosphere пакета); липсва → подразбирания
 * @property {number} [maxAniso]    renderer.capabilities.getMaxAnisotropy()
 */

/**
 * Генерира цялата статична геометрия на пистата.
 *
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit Визуалната идентичност
 * @param {TrackMeshOptions} [options]
 * @returns {THREE.Group}
 */
export function buildTrackMeshes(track, circuit, options = {}) {
    const lowPower = options.lowPower ?? false;
    const look = options.look ?? circuit.look ?? null;
    const night = circuit.atmosphere?.night === true;
    const shared = {
        lowPower,
        quality: options.quality ?? null,
        look,
        maxAniso: options.maxAniso ?? 1,
    };

    const group = new THREE.Group();

    // Семплерът на терена е ОБЩ за terrain mesh-а, дърветата, сградите и
    // трибуните — една повърхност. Договорът описва метода ту като heightAt,
    // ту като height; приемаме и двете, за да не зависим от чуждия избор.
    const sampler = createTerrainSampler(track, circuit, shared);
    const terrainHeight =
        typeof sampler.heightAt === 'function'
            ? (x, z) => sampler.heightAt(x, z)
            : (x, z) => sampler.height(x, z);

    // Декорът ПЪРВИ: питлейнът и чакълът определят къде вержът остава нисък.
    const decor = buildCircuitDecor(track, circuit, sampler, shared);
    group.add(decor.group);

    // Асфалтът и тревата тръгват с процедурен цвят (material.color) и
    // НЕУТРАЛНИ vertex цветове (1 ± вариация). Когато PBR текстурите се
    // заредят, Game.js сменя само map/color — вариацията по върховете остава
    // и чупи повторението на тайла по правите.
    const asphalt = buildAsphalt(track);
    const verge = buildVerge(track, loweredVergeRows(track, circuit, decor.pitRange));
    const kerbs = buildKerbs(track, shared);
    const startLine = buildStartLine(track);
    group.add(verge, asphalt, kerbs, startLine);

    const surfaces = { asphalt: asphalt.material, grass: verge.material, kerb: kerbs.material };
    if (decor.gravelMaterial) {
        surfaces.gravel = decor.gravelMaterial;
    }

    // Теренът (с хоризонта), гората и фонът — собствени модули; тук само се
    // закачат и се събират техните per-frame/dispose куки.
    const terrain = buildTerrain(track, circuit, sampler, shared);
    group.add(terrain.group);
    registerMaterials(surfaces, terrain.materials);

    const vegetation = buildVegetation(track, circuit, sampler, shared);
    group.add(vegetation.group);

    const backdrop = buildBackdrop(track, circuit, shared);
    group.add(backdrop.group);

    // На градска писта стълбчетата са безсмислени (стените са навсякъде), а в
    // диапазона на питовете се сблъскват с комплекса.
    if (!circuit.streetWalls) {
        group.add(buildDistanceMarkers(track, decor.pitRange));
    }

    // Публиката (facades.js, 512² canvas) е една за OSM трибуните и
    // процедурните; UV-тата им са в метри/10 (CROWD_METRES_PER_REPEAT).
    let crowdTexture = null;
    const crowd = () => {
        if (crowdTexture === null) {
            crowdTexture = makeCrowdTexture(circuit.crowdAccent);
            crowdTexture.anisotropy = shared.maxAniso;
        }
        return crowdTexture;
    };

    for (const mesh of buildLandmarks(track, circuit, terrainHeight, look, night, crowd, shared)) {
        group.add(mesh);
    }

    if (circuit.startGrandstands) {
        group.add(buildGrandstands(track, circuit, decor.pitRange, terrainHeight, look, crowd));
    }

    // Маршал с кариран флаг до старт/финала — flagPivot се вее от Game.#frame
    // на летящата (финална) обиколка.
    const marshal = buildMarshal(track);
    group.add(marshal.group);

    group.userData = {
        geometryVersion: 2,
        surfaces,
        sampler,
        // Слоят на терена (setTextures/update/dispose) — за подмяната на
        // PBR текстурите от Game, както при surfaces.grass.
        terrain,
        pitRange: decor.pitRange,
        floodlights: decor.floodlights,
        crossings: decor.crossings,
        grandstandBounds: decor.grandstandBounds,
        helicopter: decor.helicopter,
        tunnel: decor.tunnel,
        setGantryText: decor.setGantryText,
        startLights: decor.startLights,
        animations: decor.animations,
        marshalPosts: decor.marshalPosts,
        marshalFlag: marshal.flagPivot,
        /**
         * Per-frame кука за модулите с камера-зависимо поведение (LOD на
         * гората, фон, терен). Game я вика след decor animations.
         *
         * @param {number} dt
         * @param {THREE.Camera} camera
         * @param {number} windTime Общ часовник на вятъра (секунди)
         */
        update(dt, camera, windTime) {
            terrain.update?.(dt, camera);
            vegetation.update?.(dt, camera, windTime);
            backdrop.update?.(camera);
        },
        /** Ресурси извън scene.traverse (атласи, инстанс буфери) на модулите. */
        dispose() {
            terrain.dispose?.();
            vegetation.dispose?.();
            backdrop.dispose?.();
            for (const disposable of decor.disposables ?? []) {
                disposable.dispose?.();
            }
        },
    };

    return group;
}

/**
 * Материалите на терена влизат в surfaces под своите имена (terrain, ground…)
 * — там ги намират surfaceShader и подмяната на текстурите. Един материал
 * без име се записва като `terrain`.
 *
 * @param {Record<string, THREE.Material>} surfaces
 * @param {THREE.Material|Record<string, THREE.Material>|undefined} materials
 */
function registerMaterials(surfaces, materials) {
    if (!materials) {
        return;
    }
    if (materials.isMaterial) {
        surfaces.terrain = materials;
        return;
    }
    for (const [name, material] of Object.entries(materials)) {
        if (material?.isMaterial && !(name in surfaces)) {
            surfaces[name] = material;
        }
    }
}

// ── Платно и банкет ─────────────────────────────────────────────────────

/**
 * Асфалтовата лента: две станции (±halfWidths) + пълният набор атрибути за
 * шейдъра.
 *
 * @param {import('./track.js').Track} track
 * @returns {THREE.Mesh}
 */
function buildAsphalt(track) {
    const geometry = ribbonGeometry(
        track,
        [(i) => -track.halfWidths[i], (i) => track.halfWidths[i]],
        () => Y.asphalt,
        { variation: 0.06, surface: true }
    );

    const mesh = new THREE.Mesh(geometry, surfaceMaterial(COLORS.asphalt));
    mesh.frustumCulled = false; // цяла обиколка — сферата винаги пресича фрустума

    return mesh;
}

/**
 * Вержът: две странични ленти (никаква трева под асфалта — −45 % скрит
 * overdraw), всяка с 4 станции: ръб на асфалта (Y −0.03), коляно на 0.35 m
 * (прашният банкет — surfaceShader го тонира по aLateral), край на вержа и
 * заровен край на полата. Двете половини са един mesh (един draw call).
 *
 * @param {import('./track.js').Track} track
 * @param {[Uint8Array, Uint8Array]} lowered Редове с нисък верж по страна [−, +]
 * @returns {THREE.Mesh}
 */
function buildVerge(track, lowered) {
    const half = (i) => track.halfWidths[i];
    const baseY = (i, offset) => (lowered[offset < 0 ? 0 : 1][i] ? Y.grassLow : Y.grass);
    const options = { variation: 0.1 };

    const right = ribbonGeometry(
        track,
        [
            (i) => half(i),
            (i) => half(i) + SHOULDER_WIDTH,
            (i) => half(i) + RUNOFF_WIDTH,
            (i) => half(i) + RUNOFF_WIDTH + SKIRT_WIDTH,
        ],
        baseY,
        options
    );
    const left = ribbonGeometry(
        track,
        [
            (i) => -(half(i) + RUNOFF_WIDTH + SKIRT_WIDTH),
            (i) => -(half(i) + RUNOFF_WIDTH),
            (i) => -(half(i) + SHOULDER_WIDTH),
            (i) => -half(i),
        ],
        baseY,
        options
    );

    const merged = mergeGeometries([left, right], false);
    left.dispose();
    right.dispose();
    merged.computeBoundingSphere();

    const mesh = new THREE.Mesh(merged, surfaceMaterial(COLORS.grass));
    mesh.frustumCulled = false;

    return mesh;
}

/**
 * Редовете, в които вержът остава на старото ниско ниво (Y.grassLow), по
 * страна: run-off зоните (чакълът на decor.js лежи на −0.10 и трябва да е
 * НАД тревата) и питлейнът (лентите на пит комплекса). Преходът е 9 cm за
 * един 4 m ред — невидим.
 *
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @param {{from: number, to: number, sign: number}} pitRange
 * @returns {[Uint8Array, Uint8Array]} [отрицателна страна, положителна страна]
 */
function loweredVergeRows(track, circuit, pitRange) {
    const { count } = track;
    const lowered = [new Uint8Array(count), new Uint8Array(count)];
    const mark = (from, to, side) => {
        const table = lowered[side < 0 ? 0 : 1];
        for (let r = from; r <= to; r++) {
            table[((r % count) + count) % count] = 1;
        }
    };

    if (circuit.runoff !== 'none') {
        for (const range of runoffRanges(track)) {
            // Зоната е от ВЪНШНАТА страна на завоя — срещу знака на кривината.
            mark(range.from, range.to, -range.side);
        }
    }

    if (pitRange.from !== pitRange.to) {
        mark(pitRange.from, pitRange.to, pitRange.sign);
    }

    return lowered;
}

/**
 * Процедурният материал на повърхност: плътен цвят + неутрални vertex
 * цветове. Game.#loadTrackTextures подменя map/color и ПАЗИ vertexColors.
 *
 * @param {number} color
 * @returns {THREE.MeshStandardMaterial}
 */
function surfaceMaterial(color) {
    return new THREE.MeshStandardMaterial({ color, vertexColors: true, metalness: 0, roughness: 0.9 });
}

/**
 * Лента по осевата линия с произволен брой странични станции.
 *
 * Отместванията са в метри спрямо осевата линия, положително по нормалата
 * (надясно по посоката), в НАРАСТВАЩ ред — навивката на триъгълниците е
 * фиксирана към този ред. Височината идва от vergeHeight (профил + спад
 * извън платното + банкинг).
 *
 * Атрибути: uv (метрични, TILE), color (неутрални 1 ± variation), aLateral,
 * aAlong, aHalfWidth; при options.surface и aLine (състезателната линия),
 * aCurv (|кривина на линията|), aBrake (0..1 в спирачните зони), aBank,
 * aGrad — surfaceShader рисува гумата, спирачните следи и ръбовите линии
 * аналитично от тях.
 *
 * @param {import('./track.js').Track} track
 * @param {Array<(i: number) => number>} stations Отмествания по ред, нарастващи
 * @param {(i: number, offset: number) => number} baseY Слой над повърхността
 * @param {{variation?: number, surface?: boolean}} options
 * @returns {THREE.BufferGeometry}
 */
function ribbonGeometry(track, stations, baseY, options = {}) {
    const { xs, zs, nx, nz, count, spacing, curvature, halfWidths, bankSlope, gradient, raceOffset, raceCurv } = track;

    // +1 ред върхове: последният дублира първия, за да се затвори цикълът с
    // непрекъснато v (aAlong = count·spacing е цял брой тайлове).
    const rows = count + 1;
    const n = stations.length;
    const total = rows * n;

    const positions = new Float32Array(total * 3);
    const uvs = new Float32Array(total * 2);
    const colors = new Float32Array(total * 3);
    const lateral = new Float32Array(total);
    const along = new Float32Array(total);
    const halfWidth = new Float32Array(total);
    const indices = new Uint32Array(count * (n - 1) * 6);

    const surface = options.surface === true;
    const line = surface ? new Float32Array(total) : null;
    const curv = surface ? new Float32Array(total) : null;
    const brake = surface ? new Float32Array(total) : null;
    const bank = surface ? new Float32Array(total) : null;
    const grad = surface ? new Float32Array(total) : null;
    const brakeRows = surface ? brakeWeights(track) : null;

    const variation = options.variation ?? 0;

    for (let r = 0; r < rows; r++) {
        const i = r % count;
        const s = r * spacing;

        // Радиусът на завоя е 1/κ. Лента, по-широка от радиуса, се сгъва навътре
        // отвъд центъра на кривината и прави каша (Монако Fairmont ~16 m, тесните
        // завои на Спа). Ограничаваме отместването до 80% от радиуса, само от
        // ВЪТРЕШНАТА (вдлъбната) страна — външната не се сгъва.
        const k = curvature[i];
        const innerLimit = k !== 0 ? 0.8 / k : 0;

        for (let st = 0; st < n; st++) {
            let offset = stations[st](i);
            if (k > 0) {
                offset = Math.min(offset, innerLimit);
            } else if (k < 0) {
                offset = Math.max(offset, innerLimit);
            }

            const v = r * n + st;
            const vi = v * 3;

            positions[vi] = xs[i] + nx[i] * offset;
            positions[vi + 1] = vergeHeight(track, i, offset, baseY(i, offset));
            positions[vi + 2] = zs[i] + nz[i] * offset;

            uvs[v * 2] = offset / TILE;
            uvs[v * 2 + 1] = s / TILE;

            // Детерминиран псевдошум по индекс около 1: чупи повторението на
            // текстурата, без Math.random (би мъждукало при презареждане).
            const noise = variation > 0 ? (hashNoise(i * 2 + st) - 0.5) * variation : 0;
            colors[vi] = 1 + noise;
            colors[vi + 1] = 1 + noise;
            colors[vi + 2] = 1 + noise;

            lateral[v] = offset;
            along[v] = s;
            halfWidth[v] = halfWidths[i];

            if (surface) {
                line[v] = raceOffset[i];
                curv[v] = Math.abs(raceCurv[i]);
                brake[v] = brakeRows[i];
                bank[v] = bankSlope[i];
                grad[v] = gradient[i];
            }
        }
    }

    let t = 0;
    for (let r = 0; r < count; r++) {
        for (let st = 0; st < n - 1; st++) {
            const a = r * n + st;
            const c = a + n;

            indices[t++] = a;
            indices[t++] = a + 1;
            indices[t++] = c;
            indices[t++] = a + 1;
            indices[t++] = c + 1;
            indices[t++] = c;
        }
    }

    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    geometry.setAttribute('uv', new THREE.BufferAttribute(uvs, 2));
    geometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));
    geometry.setAttribute('aLateral', new THREE.BufferAttribute(lateral, 1));
    geometry.setAttribute('aAlong', new THREE.BufferAttribute(along, 1));
    geometry.setAttribute('aHalfWidth', new THREE.BufferAttribute(halfWidth, 1));
    if (surface) {
        geometry.setAttribute('aLine', new THREE.BufferAttribute(line, 1));
        geometry.setAttribute('aCurv', new THREE.BufferAttribute(curv, 1));
        geometry.setAttribute('aBrake', new THREE.BufferAttribute(brake, 1));
        geometry.setAttribute('aBank', new THREE.BufferAttribute(bank, 1));
        geometry.setAttribute('aGrad', new THREE.BufferAttribute(grad, 1));
    }
    geometry.setIndex(new THREE.BufferAttribute(indices, 1));

    // Върху наклонено трасе нормалите вече не сочат нагоре — от тях зависи
    // дали склонът ще се вижда като склон, или като плоско петно.
    geometry.computeVertexNormals();
    geometry.computeBoundingSphere();

    return geometry;
}

/**
 * Тежест на спирачната зона по ред (0..1): избледнява от 110 m преди всеки
 * тесен завой до 1 на входа му и продължава 12 m след него — същата рецепта
 * като спирачните следи в decor.js, за да съвпадат с гумата на шейдъра.
 *
 * @param {import('./track.js').Track} track
 * @returns {Float32Array}
 */
function brakeWeights(track) {
    const { count, spacing } = track;
    const weights = new Float32Array(count);

    // Слети диапазони: два съседни завоя (шикан) са едно спирачно събитие.
    const events = [];
    for (const range of curvatureRanges(track, 0.014, 4)) {
        const last = events[events.length - 1];
        if (last && range.from - last.to < 12) {
            last.to = range.to;
        } else {
            events.push({ from: range.from, to: range.to });
        }
    }

    const lead = Math.round(110 / spacing);
    const tail = Math.round(12 / spacing);

    for (const event of events) {
        const rows = lead + tail + 1;
        for (let r = 0; r < rows; r++) {
            const i = (((event.from - lead + r) % count) + count) % count;
            weights[i] = Math.max(weights[i], smoothstep(0.15, 0.95, r / rows));
        }
    }

    return weights;
}

// ── Кербове ─────────────────────────────────────────────────────────────

/**
 * Кербове в завоите: FIA профил, по един „обект" на завой в BatchedMesh
 * (един draw call, но per-range frustum culling — предпоставка за евтини
 * shadow pass-ове).
 *
 * Профил на под-ред (от асфалта навън): устна на нивото на платното (за да
 * няма ръб между боята и асфалта), вътрешен връх на 2 cm, външен връх с
 * триъгълно назъбване (KERB_PERIOD) и външна стена до −10 cm — заровена във
 * вержа, така че кербът не е плаваща лента. Червено/бяло идва от картата
 * (2 блокчета на тайл, v = s / (2·KERB_BLOCK)) — резки граници, независими от
 * стъпката на под-редовете.
 *
 * Физиката чете кербовете САМО от track.js (findKerbRanges/halfWidths) —
 * геометрията тук е презентация и не влияе на времената.
 *
 * @param {import('./track.js').Track} track
 * @param {{lowPower: boolean, maxAniso: number}} options
 * @returns {THREE.BatchedMesh|THREE.Mesh}
 */
function buildKerbs(track, options) {
    const paint = makeKerbPaint(options);
    const material = new THREE.MeshStandardMaterial({
        map: paint.map,
        roughnessMap: paint.roughnessMap,
        // С roughnessMap three УМНОЖАВА roughness по картата — базата е 1.
        roughness: paint.roughnessMap ? 1 : 0.5,
        metalness: 0.05,
    });

    const parts = [];
    for (const range of findKerbRanges(track)) {
        const part = kerbRangeGeometry(track, range);
        if (part) {
            parts.push(part);
        }
    }

    if (parts.length === 0) {
        const empty = new THREE.Mesh(new THREE.BufferGeometry(), material);
        empty.visible = false;
        return empty;
    }

    let vertexCount = 0;
    let indexCount = 0;
    for (const part of parts) {
        vertexCount += part.attributes.position.count;
        indexCount += part.index.count;
    }

    const batch = new THREE.BatchedMesh(parts.length, vertexCount, indexCount, material);
    for (const part of parts) {
        batch.addInstance(batch.addGeometry(part));
        part.dispose();
    }
    batch.computeBoundingSphere();
    batch.castShadow = false;

    return batch;
}

/**
 * Геометрията на един керб (диапазон от редове), интерполирана на KERB_STEP
 * по същия начин, по който track.heightAt/bankAt интерполират за физиката —
 * визуалната височина никога не се отдалечава от физическата с повече от
 * профила на керба.
 *
 * @param {import('./track.js').Track} track
 * @param {{from: number, to: number, side: number}} range
 * @returns {THREE.BufferGeometry|null}
 */
function kerbRangeGeometry(track, range) {
    const { spacing } = track;
    const side = range.side;
    const lengthM = (range.to - range.from) * spacing;
    if (lengthM <= 0) {
        return null;
    }

    const steps = Math.ceil(lengthM / KERB_STEP);
    const subRows = steps + 1;

    // 6 върха на под-ред: устна→вътрешен връх, вътрешен връх→външен връх,
    // външен връх→дъно на стената. Дублираните върхове дават резки ръбове
    // (нормалите не се усредняват през ръба на стената).
    const STATIONS = 6;
    const total = subRows * STATIONS;
    const positions = new Float32Array(total * 3);
    const uvs = new Float32Array(total * 2);
    const lateral = new Float32Array(total);
    const along = new Float32Array(total);
    const halfWidth = new Float32Array(total);
    const indices = new Uint32Array(steps * 3 * 6);

    const row = { x: 0, y: 0, z: 0, nx: 0, nz: 0, half: 0, bank: 0 };

    for (let k = 0; k < subRows; k++) {
        const s = Math.min(k * KERB_STEP, lengthM);
        lerpRow(track, range.from + s / spacing, row);

        const serration = KERB_HEIGHT * (0.55 + 0.45 * Math.abs(fract(s / KERB_PERIOD) * 2 - 1));
        const edge = side * row.half;
        // [напречно от ръба навън, височина над платното, u в картата]
        const profile = [
            [-KERB_LIP, Y.kerbLip, 0],
            [0, Y.kerbInner, 0.06],
            [0, Y.kerbInner, 0.06],
            [KERB_WIDTH, serration, 1],
            [KERB_WIDTH, serration, 1],
            [KERB_WIDTH, Y.kerbOuterBottom, 1],
        ];

        for (let st = 0; st < STATIONS; st++) {
            const [across, height, u] = profile[st];
            const offset = edge + side * across;
            const v = k * STATIONS + st;
            const vi = v * 3;

            positions[vi] = row.x + row.nx * offset;
            positions[vi + 1] = row.y + height - offset * row.bank;
            positions[vi + 2] = row.z + row.nz * offset;

            uvs[v * 2] = u;
            uvs[v * 2 + 1] = s / (2 * KERB_BLOCK);

            lateral[v] = offset;
            along[v] = (range.from + s / spacing) * spacing;
            halfWidth[v] = row.half;
        }
    }

    // Навивката се обръща според страната: при side>0 отместванията растат
    // по нормалата (прав ред), при side<0 намаляват (огледален ред) — иначе
    // кербът гледа надолу и изчезва при backface culling.
    let t = 0;
    for (let k = 0; k < steps; k++) {
        for (const pair of [0, 2, 4]) {
            const a = k * STATIONS + pair;
            const b = a + 1;
            const c = a + STATIONS;
            const d = c + 1;

            if (side > 0) {
                indices[t++] = a; indices[t++] = b; indices[t++] = c;
                indices[t++] = b; indices[t++] = d; indices[t++] = c;
            } else {
                indices[t++] = a; indices[t++] = c; indices[t++] = b;
                indices[t++] = b; indices[t++] = c; indices[t++] = d;
            }
        }
    }

    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    geometry.setAttribute('uv', new THREE.BufferAttribute(uvs, 2));
    geometry.setAttribute('aLateral', new THREE.BufferAttribute(lateral, 1));
    geometry.setAttribute('aAlong', new THREE.BufferAttribute(along, 1));
    geometry.setAttribute('aHalfWidth', new THREE.BufferAttribute(halfWidth, 1));
    geometry.setIndex(new THREE.BufferAttribute(indices, 1));
    geometry.computeVertexNormals();

    return geometry;
}

/**
 * Линейна интерполация на реда на дробна позиция `rowF` (в редове, може да
 * надхвърля count — wrap по модул), както heightAt/bankAt в track.js.
 *
 * @param {import('./track.js').Track} track
 * @param {number} rowF
 * @param {{x: number, y: number, z: number, nx: number, nz: number, half: number, bank: number}} out
 */
function lerpRow(track, rowF, out) {
    const { xs, ys, zs, nx, nz, count, halfWidths, bankSlope } = track;
    const base = Math.floor(rowF);
    const t = rowF - base;
    const i0 = ((base % count) + count) % count;
    const i1 = (i0 + 1) % count;

    out.x = xs[i0] + (xs[i1] - xs[i0]) * t;
    out.y = ys[i0] + (ys[i1] - ys[i0]) * t;
    out.z = zs[i0] + (zs[i1] - zs[i0]) * t;

    const mx = nx[i0] + (nx[i1] - nx[i0]) * t;
    const mz = nz[i0] + (nz[i1] - nz[i0]) * t;
    const len = Math.hypot(mx, mz) || 1;
    out.nx = mx / len;
    out.nz = mz / len;

    out.half = halfWidths[i0] + (halfWidths[i1] - halfWidths[i0]) * t;
    out.bank = bankSlope[i0] + (bankSlope[i1] - bankSlope[i0]) * t;
}

/**
 * Боята на керба: един тайл = червено + бяло блокче (v), u през ширината
 * (0 = устна към асфалта, 1 = външен ръб). Драскотини и начупени ръбове по
 * границите на блокчетата, сива бетонна устна, потъмняване към асфалта.
 * Десктоп: и roughness карта (боя 0.45, драскотини 0.7). Детерминирана
 * (hashNoise) — еднаква при всяко зареждане.
 *
 * @param {{lowPower: boolean, maxAniso: number}} options
 * @returns {{map: THREE.CanvasTexture, roughnessMap: THREE.CanvasTexture|null}}
 */
function makeKerbPaint(options) {
    const w = 64;
    const h = 256;
    const half = h / 2;

    const color = document.createElement('canvas');
    color.width = w;
    color.height = h;
    const cc = color.getContext('2d');

    const rough = options.lowPower ? null : document.createElement('canvas');
    const rc = rough ? rough.getContext('2d') : null;
    if (rough) {
        rough.width = w;
        rough.height = h;
    }

    cc.fillStyle = cssColor(COLORS.kerbRed);
    cc.fillRect(0, 0, w, half);
    cc.fillStyle = cssColor(COLORS.kerbWhite);
    cc.fillRect(0, half, w, half);

    if (rc) {
        rc.fillStyle = 'rgb(115,115,115)'; // roughness 0.45 (зелен канал)
        rc.fillRect(0, 0, w, h);
    }

    // Драскотини: къси тъмни щрихи, гъсти около границите на блокчетата
    // (v = 0, половина, край — където гумите удрят ръба на боята).
    for (let n = 0; n < 90; n++) {
        const nearEdge = hashNoise(n * 3.1) < 0.7;
        const anchor = [0, half, h][Math.floor(hashNoise(n * 5.7) * 3)];
        const y = nearEdge
            ? anchor + (hashNoise(n * 7.3) - 0.5) * 18
            : hashNoise(n * 7.3) * h;
        const x = hashNoise(n * 2.9) * w;
        const len = 3 + hashNoise(n * 11.3) * 14;
        const thick = 1 + hashNoise(n * 13.1) * 1.5;

        cc.fillStyle = `rgba(30,28,26,${(0.18 + hashNoise(n * 17.9) * 0.3).toFixed(2)})`;
        cc.fillRect(x, y, len, thick);
        if (rc) {
            rc.fillStyle = 'rgb(178,178,178)'; // 0.7 — драскотината е матова
            rc.fillRect(x, y, len, thick);
        }
    }

    // Пръски и прах — фина зърнистост, за да не е плоска боя.
    for (let n = 0; n < 500; n++) {
        cc.fillStyle = `rgba(20,20,22,${(0.05 + hashNoise(n * 1.7 + 3) * 0.1).toFixed(2)})`;
        cc.fillRect(hashNoise(n * 2.3 + 1) * w, hashNoise(n * 4.1 + 2) * h, 1.5, 1.5);
    }

    // Гумата потъмнява устната и първите сантиметри към асфалта.
    const grime = cc.createLinearGradient(0, 0, w * 0.25, 0);
    grime.addColorStop(0, 'rgba(40,38,36,0.55)');
    grime.addColorStop(1, 'rgba(40,38,36,0)');
    cc.fillStyle = grime;
    cc.fillRect(0, 0, w * 0.25, h);

    // Бетонната устна (u < 0.06).
    cc.fillStyle = '#8f8d88';
    cc.fillRect(0, 0, Math.round(w * 0.06), h);
    if (rc) {
        rc.fillStyle = 'rgb(210,210,210)';
        rc.fillRect(0, 0, Math.round(w * 0.06), h);
    }

    const map = new THREE.CanvasTexture(color);
    map.colorSpace = THREE.SRGBColorSpace;
    map.wrapS = THREE.ClampToEdgeWrapping;
    map.wrapT = THREE.RepeatWrapping;
    map.anisotropy = options.maxAniso;

    let roughnessMap = null;
    if (rough) {
        roughnessMap = new THREE.CanvasTexture(rough);
        roughnessMap.wrapS = THREE.ClampToEdgeWrapping;
        roughnessMap.wrapT = THREE.RepeatWrapping;
        roughnessMap.anisotropy = options.maxAniso;
    }

    return { map, roughnessMap };
}

// ── Стартова линия ──────────────────────────────────────────────────────

/**
 * Стартово-финалната линия: шахматна лента ±0.6 m напречно на трасето, ОСВЕТЕН
 * материал (roughness 0.45 — боя), за да реагира на слънце, сянка и мъгла;
 * unlit-ът светеше плоско бяло под колата и нощем.
 *
 * @param {import('./track.js').Track} track
 * @returns {THREE.Mesh}
 */
function buildStartLine(track) {
    const { xs, ys, zs, nx, nz, tx, tz, bankSlope } = track;
    const half = track.halfWidths[0];
    const depth = 0.6;
    const cells = Math.max(2, Math.round((2 * half) / depth));
    const uMax = cells / 16; // картата е 16 клетки широка

    const positions = [];
    const uvs = [];

    for (const [along, side, u, v] of [
        [-depth, -half, 0, 0],
        [-depth, half, uMax, 0],
        [depth, -half, 0, 1],
        [depth, half, uMax, 1],
    ]) {
        positions.push(
            xs[0] + nx[0] * side + tx[0] * along,
            ys[0] + Y.startLine - side * bankSlope[0],
            zs[0] + nz[0] * side + tz[0] * along
        );
        uvs.push(u, v);
    }

    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
    geometry.setAttribute('uv', new THREE.Float32BufferAttribute(uvs, 2));
    geometry.setIndex([0, 1, 2, 1, 3, 2]);
    geometry.computeVertexNormals();
    geometry.computeBoundingSphere();

    return new THREE.Mesh(
        geometry,
        new THREE.MeshStandardMaterial({ map: makeCheckeredTexture(16, 2), metalness: 0, roughness: 0.45 })
    );
}

// ── Ориентири (OSM) ─────────────────────────────────────────────────────

/**
 * Реалните трибуни и сгради около пистата, от OpenStreetMap.
 *
 * Това е разликата между „някакво трасе с правилната форма" и разпознаваемо
 * място: стените от трибуни покрай стартовата права на Монца или каньонът на
 * Монако се четат от една снимка. (Дърветата от същия източник строи
 * vegetation.js.)
 *
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @param {(x: number, z: number) => number} terrainHeight
 * @param {object|null} look
 * @param {boolean} night
 * @param {() => THREE.CanvasTexture} crowd Споделената текстура на публиката
 * @param {{lowPower: boolean, maxAniso: number}} options
 * @returns {THREE.Object3D[]}
 */
function buildLandmarks(track, circuit, terrainHeight, look, night, crowd, options) {
    const landmarks = track.landmarks;

    if (!landmarks) {
        return [];
    }

    const out = [];

    const grandstands = buildOsmGrandstands(track, landmarks.grandstands ?? [], terrainHeight, crowd);
    if (grandstands) {
        out.push(grandstands);
    }

    // Изхвърляме сградите, които попадат върху/до трасето — в град като Монако
    // OSM има footprint-и точно на пистата (бежевите блокове през асфалта).
    // Височината е част от идентичността: жилищните блокове на Монако правят
    // каньона, а паддок постройките на Силвърстоун са ниски.
    const buildings = buildFacades(
        track,
        (landmarks.buildings ?? []).filter((ring) => !overlapsTrack(track, ring, track.width / 2 + 3)),
        circuit.buildingHeight ?? LANDMARK_HEIGHT.building,
        terrainHeight,
        circuit,
        look,
        night,
        options
    );
    if (buildings) {
        out.push(buildings);
    }

    return out;
}

/**
 * OSM сградите: слети в един mesh с материала от facades.js — фасадна плочка
 * върху метричните UV на ExtrudeGeometry, плосък покрив по нормалата, нощем
 * разпръснати светещи прозорци според circuit.look.facade.
 *
 * @param {import('./track.js').Track} track
 * @param {Array<Array<[number, number]>>} rings
 * @param {number} baseHeight
 * @param {(x: number, z: number) => number} terrainHeight
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @param {object|null} look
 * @param {boolean} night
 * @param {{lowPower: boolean, maxAniso: number}} options
 * @returns {THREE.Mesh|null}
 */
function buildFacades(track, rings, baseHeight, terrainHeight, circuit, look, night, options) {
    const geometries = [];

    for (let r = 0; r < rings.length; r++) {
        // Лека вариация, за да не е равен блок от еднакви кутии.
        const height = baseHeight * (0.75 + hashNoise(r * 7 + 1) * 0.6);
        const geometry = extrudeRing(rings[r], height, terrainHeight);
        if (geometry) {
            geometries.push(geometry);
        }
    }

    const merged = mergeAll(geometries);
    if (!merged) {
        return null;
    }

    const material = buildingMaterial(circuit, { night, look, lowPower: options.lowPower });
    if (material.map) {
        material.map.anisotropy = options.maxAniso;
    }

    const mesh = new THREE.Mesh(merged, material);
    mesh.frustumCulled = true;

    return mesh;
}

/**
 * OSM трибуните: цокъл, наклонена седалкова банка с публика по ръбовете,
 * които гледат към трасето, вертикални стени по останалите и покривна плоча.
 * Един mesh с две групи (конструкция / публика) → 2 draw call-а за всички.
 *
 * @param {import('./track.js').Track} track
 * @param {Array<Array<[number, number]>>} rings
 * @param {(x: number, z: number) => number} terrainHeight
 * @param {() => THREE.CanvasTexture} crowd
 * @returns {THREE.Mesh|null}
 */
function buildOsmGrandstands(track, rings, terrainHeight, crowd) {
    const structureParts = [];
    const crowdParts = [];

    for (let r = 0; r < rings.length; r++) {
        const ring = rings[r];
        if (ring.length < 3) {
            continue;
        }

        const height = LANDMARK_HEIGHT.grandstand * (0.85 + hashNoise(r * 7 + 1) * 0.3);
        const plinthHeight = height * 0.3;
        const centroid = ringCentroid(ring);
        const base = terrainHeight(centroid[0], centroid[1]) - 0.4;

        const plinth = extrudeRing(ring, plinthHeight, terrainHeight);
        const roof = extrudeRing(ring, 0.4, terrainHeight);
        if (!plinth || !roof) {
            continue;
        }
        roof.translate(0, height, 0);
        structureParts.push(plinth, roof);

        const outwardSign = ringArea(ring) > 0 ? 1 : -1;
        const walls = new QuadBuilder();
        const seats = new QuadBuilder();
        const n = ring.length;

        for (let j = 0; j < n; j++) {
            const [ax, az] = ring[j];
            const [bx, bz] = ring[(j + 1) % n];
            const dx = bx - ax;
            const dz = bz - az;
            const len = Math.hypot(dx, dz);
            if (len < 1) {
                continue;
            }

            // Външна нормала на ръба според ориентацията на контура.
            const ox = (outwardSign * dz) / len;
            const oz = (-outwardSign * dx) / len;
            const faceX = ox;
            const faceZ = oz;

            if (len >= 6 && edgeFacesTrack(track, (ax + bx) / 2, (az + bz) / 2, ox, oz)) {
                // Седалките се качват навътре: наклон ~28°, но не по-дълбоко
                // от половината контур (тесни трибуни).
                const rise = height - plinthHeight;
                const depth = Math.min(rise * 1.3, ringDepthAlong(ring, ax, az, -ox, -oz) * 0.5);
                const slope = Math.hypot(depth, rise);

                seats.quad(
                    [ax, base + plinthHeight, az],
                    [bx, base + plinthHeight, bz],
                    [bx - ox * depth, base + height, bz - oz * depth],
                    [ax - ox * depth, base + height, az - oz * depth],
                    len / 10,
                    slope / 10,
                    faceX,
                    faceZ
                );
            } else {
                walls.quad(
                    [ax, base + plinthHeight, az],
                    [bx, base + plinthHeight, bz],
                    [bx, base + height, bz],
                    [ax, base + height, az],
                    len / 4,
                    (height - plinthHeight) / 4,
                    faceX,
                    faceZ
                );
            }
        }

        const wallGeometry = walls.build();
        if (wallGeometry) {
            structureParts.push(wallGeometry);
        }
        const seatGeometry = seats.build();
        if (seatGeometry) {
            crowdParts.push(seatGeometry);
        }
    }

    const structure = mergeAll(structureParts);
    if (!structure) {
        return null;
    }

    const structureMaterial = new THREE.MeshStandardMaterial({ color: COLORS.grandstand, metalness: 0.1, roughness: 0.75 });
    const seating = mergeAll(crowdParts);

    let mesh;
    if (seating) {
        const merged = mergeGeometries([structure, seating], true);
        structure.dispose();
        seating.dispose();
        const crowdMaterial = new THREE.MeshStandardMaterial({ map: crowd(), metalness: 0, roughness: 0.9 });
        mesh = new THREE.Mesh(merged, [structureMaterial, crowdMaterial]);
    } else {
        mesh = new THREE.Mesh(structure, structureMaterial);
    }

    mesh.geometry.computeBoundingSphere();
    mesh.frustumCulled = true;

    return mesh;
}

/**
 * Дали ръб с външна нормала (ox, oz) в средна точка (mx, mz) гледа към
 * трасето: най-близката осева точка е пред него (под ~70°) и на < 90 m.
 *
 * @param {import('./track.js').Track} track
 * @returns {boolean}
 */
function edgeFacesTrack(track, mx, mz, ox, oz) {
    const { xs, zs, count } = track;
    let bestSq = Infinity;
    let bestI = 0;

    for (let i = 0; i < count; i += 4) {
        const dx = xs[i] - mx;
        const dz = zs[i] - mz;
        const dSq = dx * dx + dz * dz;
        if (dSq < bestSq) {
            bestSq = dSq;
            bestI = i;
        }
    }

    const dist = Math.sqrt(bestSq);
    if (dist > 90 || dist < 1e-3) {
        return false;
    }

    const toTrack = ((xs[bestI] - mx) * ox + (zs[bestI] - mz) * oz) / dist;

    return toTrack > 0.34;
}

/**
 * Колко дълбок е контурът навътре от точка (ax, az) по посока (dx, dz).
 *
 * @param {Array<[number, number]>} ring
 * @returns {number}
 */
function ringDepthAlong(ring, ax, az, dx, dz) {
    let depth = 0;
    for (const [x, z] of ring) {
        depth = Math.max(depth, (x - ax) * dx + (z - az) * dz);
    }

    return depth;
}

/** Знакова площ на контура (ориентация) в равнината XZ. */
function ringArea(ring) {
    let area = 0;
    for (let j = 0; j < ring.length; j++) {
        const [ax, az] = ring[j];
        const [bx, bz] = ring[(j + 1) % ring.length];
        area += ax * bz - bx * az;
    }

    return area / 2;
}

/**
 * Натрупва четириъгълници (несподелени върхове → плоски нормали) с UV в
 * метри/мащаб и навивка, обърната към зададената посока.
 */
class QuadBuilder {
    constructor() {
        this.positions = [];
        this.uvs = [];
        this.indices = [];
    }

    /**
     * @param {number[]} a Долу-ляво
     * @param {number[]} b Долу-дясно
     * @param {number[]} c Горе-дясно
     * @param {number[]} d Горе-ляво
     * @param {number} u Ширина в тайлове
     * @param {number} v Височина в тайлове
     * @param {number} faceX Посока (XZ), към която гледа лицето
     * @param {number} faceZ
     */
    quad(a, b, c, d, u, v, faceX, faceZ) {
        const start = this.positions.length / 3;
        this.positions.push(...a, ...b, ...c, ...d);
        this.uvs.push(0, 0, u, 0, u, v, 0, v);

        // Нормала на (a, b, c); обръщаме реда, ако сочи срещу лицето.
        const abx = b[0] - a[0];
        const aby = b[1] - a[1];
        const abz = b[2] - a[2];
        const acx = c[0] - a[0];
        const acy = c[1] - a[1];
        const acz = c[2] - a[2];
        const nx = aby * acz - abz * acy;
        const nz = abx * acy - aby * acx;
        const flip = nx * faceX + nz * faceZ < 0;

        if (flip) {
            this.indices.push(start, start + 2, start + 1, start, start + 3, start + 2);
        } else {
            this.indices.push(start, start + 1, start + 2, start, start + 2, start + 3);
        }
    }

    /**
     * Неиндексирана геометрия — ExtrudeGeometry също е такава, а
     * mergeGeometries отказва микс от индексирани и неиндексирани части.
     *
     * @returns {THREE.BufferGeometry|null}
     */
    build() {
        if (this.indices.length === 0) {
            return null;
        }
        const indexed = new THREE.BufferGeometry();
        indexed.setAttribute('position', new THREE.Float32BufferAttribute(this.positions, 3));
        indexed.setAttribute('uv', new THREE.Float32BufferAttribute(this.uvs, 2));
        indexed.setIndex(this.indices);

        const geometry = indexed.toNonIndexed();
        indexed.dispose();
        geometry.computeVertexNormals();

        return geometry;
    }
}

/**
 * Издига един контур в обем, седнал върху общия терен (семплера) — същата
 * мрежа рендерира и релефа, така че сграда на хълм стои НА хълма, не виси до
 * него. Лекият минус компенсира наклона на терена под широк контур.
 *
 * @param {Array<[number, number]>} ring
 * @param {number} height
 * @param {(x: number, z: number) => number} terrainHeight
 * @returns {THREE.BufferGeometry|null}
 */
function extrudeRing(ring, height, terrainHeight) {
    if (ring.length < 3) {
        return null;
    }

    // Shape живее в XY; z се обръща, за да съвпадне с XZ след ротацията.
    const shape = new THREE.Shape(ring.map(([x, z]) => new THREE.Vector2(x, -z)));

    let geometry;
    try {
        geometry = new THREE.ExtrudeGeometry(shape, {
            depth: height,
            bevelEnabled: false,
            curveSegments: 1,
        });
    } catch {
        // Самопресичащ се контур — OSM ги има; пропускаме тихо.
        return null;
    }

    geometry.rotateX(-Math.PI / 2);
    const centroid = ringCentroid(ring);
    geometry.translate(0, terrainHeight(centroid[0], centroid[1]) - 0.4, 0);

    return geometry;
}

/**
 * Слива геометрии в една и освобождава частите. Сливането не е разкош: 160
 * отделни сгради са 160 draw call-а и сами по себе си свалят кадрите на
 * телефон под играбилното.
 *
 * @param {THREE.BufferGeometry[]} geometries
 * @returns {THREE.BufferGeometry|null}
 */
function mergeAll(geometries) {
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
    merged.computeBoundingSphere();

    return merged;
}

/**
 * Дали контур (сграда) попада на по-малко от `clearance` метра от осевата линия
 * — т.е. върху/до трасето. Проверката е по ръбове през всяка 2-ра осева точка;
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

// ── Процедурни трибуни ──────────────────────────────────────────────────

const GRANDSTAND = {
    depth: 18, // дълбочина навън, m
    height: 12, // височина, m
    section: 26, // дължина на секция по трасето, m
    span: 165, // колко от правата да покрием, m
    gapMain: 7, // отстъп от ръба на асфалта на правата, m
    gapCorner: 9, // в завоите — зад чакъла (decor.js: до half + 7.8)
};

/**
 * Процедурни трибуни: главната срещу питовете на старт/финала (както на всяка
 * реална писта) плюс банки на външната страна на трите най-остри завоя.
 * Реалните OSM ориентири са малко — това добавя „стадион" атмосфера, без да
 * зависи от пълнотата на OSM.
 *
 * Инстанцирани (тяло/покрив/борд + фигури) и без сянка — леко за телефон.
 * Публиката е САМО на наклонената банка (материал по група на BoxGeometry).
 *
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @param {{from: number, to: number, sign: number}} pitRange
 * @param {(x: number, z: number) => number} terrainHeight
 * @param {object|null} look
 * @param {() => THREE.CanvasTexture} crowd Споделената текстура на публиката
 * @returns {THREE.Group}
 */
function buildGrandstands(track, circuit, pitRange, terrainHeight, look, crowd) {
    const { xs, ys, zs, nx, nz, tx, tz, count, spacing } = track;
    const { depth: DEPTH, height: HEIGHT, section: SECTION } = GRANDSTAND;
    const group = new THREE.Group();
    const step = Math.max(1, Math.round(SECTION / spacing));

    const placements = grandstandPlacements(track, circuit, pitRange);
    const totalSections = placements.reduce((sum, p) => sum + p.sections, 0);

    const concrete = new THREE.MeshStandardMaterial({ color: COLORS.grandstand, metalness: 0.0, roughness: 0.85 });
    const crowdMat = new THREE.MeshStandardMaterial({ map: crowd(), color: 0xffffff, metalness: 0.0, roughness: 0.9 });
    const roofMat = new THREE.MeshStandardMaterial({ color: COLORS.grandstandRoof, metalness: 0.45, roughness: 0.5 });

    // Неутрални информационни пана пред главната трибуна.
    const hoarding = makeHoardingTexture();
    hoarding.repeat.set(3, 1);
    const hoardMat = new THREE.MeshStandardMaterial({ map: hoarding, metalness: 0.1, roughness: 0.6 });
    const hoardGeo = new THREE.BoxGeometry(0.3, 1.3, SECTION * 0.95);

    // Наклонена седяща банка вместо блокче: свалям горния ръб откъм трасето, за
    // да се вдига навън, както истинска трибуна. +X сочи към трасето след
    // rotation.y. Горната стена на кутията (група py = 2) става банката —
    // само тя носи публиката; UV-то ѝ се мащабира така, че един човек от
    // картата да е ≈0.5 m (20 места на тайл).
    const bodyGeo = new THREE.BoxGeometry(DEPTH, HEIGHT, SECTION * 0.9);
    {
        const p = bodyGeo.attributes.position;
        const nrm = bodyGeo.attributes.normal;
        const uv = bodyGeo.attributes.uv;
        for (let v = 0; v < p.count; v++) {
            if (nrm.getY(v) > 0.5) {
                uv.setXY(v, uv.getX(v) * (DEPTH / 10), uv.getY(v) * ((SECTION * 0.9) / 10));
            }
            if (p.getY(v) > 0) {
                const front = (p.getX(v) + DEPTH / 2) / DEPTH; // 1 откъм трасето, 0 отзад
                p.setY(v, p.getY(v) - HEIGHT * 0.6 * front);
            }
        }
        bodyGeo.computeVertexNormals();
    }
    const bodyMats = [concrete, concrete, crowdMat, concrete, concrete, concrete];

    const roofGeo = new THREE.BoxGeometry(DEPTH * 0.92, 0.4, SECTION * 0.96);

    // Инстанцирани: 3 draw call-а (тяло/покрив/борд) вместо десетки меша — и
    // толкова по-малко в сенчестия pass.
    const bodies = new THREE.InstancedMesh(bodyGeo, bodyMats, totalSections);
    const roofs = new THREE.InstancedMesh(roofGeo, roofMat, totalSections);
    const hoards = new THREE.InstancedMesh(hoardGeo, hoardMat, totalSections);

    // 3D публика на предните редове: при близък поглед текстурата е плоска,
    // а няколко десетки low-poly фигури я „отлепят". Всички торсове, ръце и
    // крака остават ЕДИН инстанциран меш; главите са вторият. По-естественият
    // силует така не добавя draw calls спрямо старите кубчета.
    const perRow = 10;
    const figureRows = 2;
    const bodyParts = [];
    const addBodyPart = (geometry, shade) => {
        const colors = new Float32Array(geometry.attributes.position.count * 3);
        colors.fill(shade);
        geometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));
        bodyParts.push(geometry);
    };
    const torso = new THREE.CylinderGeometry(0.16, 0.22, 0.42, 6, 1);
    torso.scale(1, 1, 0.75);
    torso.translate(0, 0.36, 0);
    addBodyPart(torso, 1);
    for (const side of [-1, 1]) {
        const arm = new THREE.CylinderGeometry(0.042, 0.05, 0.34, 5, 1);
        arm.rotateZ(side * 0.18);
        arm.translate(side * 0.205, 0.34, 0);
        addBodyPart(arm, 0.88);

        const leg = new THREE.CylinderGeometry(0.05, 0.06, 0.28, 5, 1);
        leg.rotateX(-0.38);
        leg.translate(side * 0.075, 0.11, 0.045);
        addBodyPart(leg, 0.26);
    }
    const figureGeo = mergeGeometries(bodyParts, false);
    for (const part of bodyParts) {
        part.dispose();
    }
    const headGeo = new THREE.SphereGeometry(0.115, 8, 6);
    headGeo.scale(0.92, 1.08, 0.94);
    headGeo.translate(0, 0.67, 0);
    const figures = new THREE.InstancedMesh(
        figureGeo,
        new THREE.MeshStandardMaterial({ color: 0xffffff, vertexColors: true, roughness: 0.92 }),
        totalSections * perRow * figureRows
    );
    const heads = new THREE.InstancedMesh(
        headGeo,
        new THREE.MeshStandardMaterial({ color: 0xffffff, roughness: 0.88 }),
        totalSections * perRow * figureRows
    );
    const shirt = new THREE.Color();
    const skin = new THREE.Color();
    const shirts = [0xa93f45, 0xd8d4ca, 0x416b9a, 0xb89a3e, 0x447a5c, 0xa8693e, 0x41464d];
    const skins = [0xf0c9aa, 0xd9aa83, 0xbd825d, 0x925b3f, 0x70442f, 0xe7b995];

    const matrix = new THREE.Matrix4();
    const position = new THREE.Vector3();
    const quaternion = new THREE.Quaternion();
    const scale = new THREE.Vector3(1, 1, 1);
    const personQuaternion = new THREE.Quaternion();
    const jitterQuaternion = new THREE.Quaternion();
    const personScale = new THREE.Vector3();
    const local = new THREE.Vector3();
    let n = 0;
    let hoard = 0;
    let figure = 0;

    for (const placement of placements) {
        const { sign, gap } = placement;

        for (let s = 0; s < placement.sections; s++) {
            const i = (((placement.startRow + s * step) % count) + count) % count;
            const half = track.halfWidths[i];
            // Наклонената банка гледа към +X в локални координати; от лявата
            // страна (sign<0) секцията се обръща на 180°, за да гледа трасето.
            quaternion.setFromAxisAngle(UP, Math.atan2(tx[i], tz[i]) + (sign < 0 ? Math.PI : 0));
            const off = sign * (half + gap + DEPTH / 2);
            const cx = xs[i] + nx[i] * off;
            const cz = zs[i] + nz[i] * off;
            // Стъпва на терена (семплера), леко вкопана — иначе виси над
            // спускащия се банкет.
            const groundY = Math.min(ys[i], terrainHeight(cx, cz)) - 0.3;

            position.set(cx, groundY + HEIGHT / 2, cz);
            bodies.setMatrixAt(n, matrix.compose(position, quaternion, scale));

            // Покрив над задната (високата) част, надвиснал над седалките.
            position.set(cx, groundY + HEIGHT + 0.4, cz);
            roofs.setMatrixAt(n, matrix.compose(position, quaternion, scale));

            // Рекламен борд пред трибуната, на банкета (в завоите там е чакъл
            // — без борд).
            if (placement.hoardings) {
                const hoardOff = sign * (half + gap * 0.4);
                position.set(
                    xs[i] + nx[i] * hoardOff,
                    vergeHeight(track, i, hoardOff, 0.65),
                    zs[i] + nz[i] * hoardOff
                );
                hoards.setMatrixAt(hoard++, matrix.compose(position, quaternion, scale));
            }

            for (let row = 0; row < figureRows; row++) {
                for (let p = 0; p < perRow; p++) {
                    const personSeed = n * perRow * figureRows + row * perRow + p + 1;
                    // Непълни редове и малко различна гъстота са по-убедителни
                    // от математическа решетка от еднакви хора.
                    if (hashNoise(personSeed * 8.17) > 0.88) {
                        continue;
                    }

                    // Локално в рамката на секцията: x към трасето, y върху
                    // наклонената банка (същата формула като смъкването на
                    // върховете ѝ).
                    const seatX = DEPTH / 2 - 1.4 - row * 2.6 + (hashNoise(personSeed * 2.31) - 0.5) * 0.28;
                    const seatFront = (seatX + DEPTH / 2) / DEPTH;
                    local.set(
                        seatX,
                        HEIGHT * (1 - 0.6 * seatFront) + 0.28,
                        (p / (perRow - 1) - 0.5) * SECTION * 0.8 + (hashNoise(personSeed * 3.73) - 0.5) * 0.42
                    );
                    local.applyQuaternion(quaternion);

                    position.set(cx + local.x, groundY + local.y, cz + local.z);
                    const width = 0.9 + hashNoise(personSeed * 4.91) * 0.2;
                    const height = 0.88 + hashNoise(personSeed * 6.13) * 0.22;
                    personScale.set(width, height, width);
                    jitterQuaternion.setFromAxisAngle(UP, (hashNoise(personSeed * 7.19) - 0.5) * 0.36);
                    personQuaternion.copy(quaternion).multiply(jitterQuaternion);
                    matrix.compose(position, personQuaternion, personScale);
                    figures.setMatrixAt(figure, matrix);
                    heads.setMatrixAt(figure, matrix);

                    shirt.set(shirts[Math.floor(hashNoise(personSeed * 3.7) * shirts.length)]);
                    skin.set(skins[Math.floor(hashNoise(personSeed * 5.21) * skins.length)]);
                    figures.setColorAt(figure, shirt);
                    heads.setColorAt(figure, skin);
                    figure++;
                }
            }

            n++;
        }

        // Защитна ограда между трасето и трибуните — по целия им фронт. Долният
        // ръб слиза под банкета (иначе виси).
        group.add(
            fenceMesh(
                track,
                placement.startRow,
                placement.startRow + placement.sections * step,
                sign * (track.halfWidths[0] + placement.fenceGap),
                -0.8,
                3.2
            )
        );
    }

    bodies.count = n;
    roofs.count = n;
    hoards.count = hoard;
    figures.count = figure;
    heads.count = figure;
    for (const mesh of [bodies, roofs, hoards, figures, heads]) {
        mesh.instanceMatrix.needsUpdate = true;
        if (mesh.instanceColor) {
            mesh.instanceColor.needsUpdate = true;
        }
        mesh.castShadow = false;
        mesh.computeBoundingSphere();
        mesh.frustumCulled = true;
        group.add(mesh);
    }

    return group;
}

/**
 * Къде застават процедурните трибуни: главната права срещу питовете и до три
 * банки на външната страна на най-острите завои — пропуснати, ако се удрят в
 * питовете, тунела, главната трибуна или OSM трибуна наблизо.
 *
 * @param {import('./track.js').Track} track
 * @param {import('./circuits.js').CircuitStyle} circuit
 * @param {{from: number, to: number, sign: number}} pitRange
 * @returns {Array<{startRow: number, sections: number, sign: number, gap: number, fenceGap: number, hoardings: boolean}>}
 */
function grandstandPlacements(track, circuit, pitRange) {
    const { xs, zs, nx, nz, count, spacing } = track;
    const { section: SECTION, span: SPAN } = GRANDSTAND;
    const step = Math.max(1, Math.round(SECTION / spacing));

    const placements = [
        {
            startRow: -Math.round(20 / spacing), // започва ~20 m преди старта
            sections: Math.max(3, Math.round(SPAN / SECTION)),
            sign: -pitRange.sign,
            gap: GRANDSTAND.gapMain,
            fenceGap: 2.0, // оградата е пред бордовете, на банкета
            hoardings: true,
        },
    ];

    const inWrapped = (row, from, to) => {
        const d = (((row - from) % count) + count) % count;
        return d <= to - from;
    };
    const rowsOverlap = (a0, a1, b0, b1, margin) => {
        for (let r = a0; r <= a1; r++) {
            if (inWrapped(r, b0 - margin, b1 + margin)) {
                return true;
            }
        }
        return false;
    };

    const tunnel = circuit.tunnel
        ? [Math.round(circuit.tunnel.from / spacing), Math.round(circuit.tunnel.to / spacing)]
        : null;
    const osmStands = (track.landmarks?.grandstands ?? []).map(ringCentroid);

    const ranges = curvatureRanges(track, 0.02, 6).sort((a, b) => b.peak - a.peak);
    for (const range of ranges) {
        if (placements.length >= 4) {
            break;
        }

        const sections = Math.max(2, Math.min(4, Math.round(((range.to - range.from) * spacing) / SECTION)));
        const startRow = Math.round((range.from + range.to) / 2 - (sections * step) / 2);
        const endRow = startRow + sections * step;
        const sign = -range.side;

        const clashes =
            placements.some((p) => rowsOverlap(startRow, endRow, p.startRow, p.startRow + p.sections * step, 6)) ||
            (pitRange.from !== pitRange.to && rowsOverlap(startRow, endRow, pitRange.from, pitRange.to, 6)) ||
            (tunnel !== null && rowsOverlap(startRow, endRow, tunnel[0], tunnel[1], 4));
        if (clashes) {
            continue;
        }

        const mid = (((startRow + Math.round((sections * step) / 2)) % count) + count) % count;
        const off = sign * (track.halfWidths[mid] + GRANDSTAND.gapCorner + GRANDSTAND.depth / 2);
        const cx = xs[mid] + nx[mid] * off;
        const cz = zs[mid] + nz[mid] * off;
        if (osmStands.some(([sx, sz]) => Math.hypot(sx - cx, sz - cz) < 45)) {
            continue;
        }

        placements.push({
            startRow,
            sections,
            sign,
            gap: GRANDSTAND.gapCorner,
            fenceGap: GRANDSTAND.gapCorner - 0.6, // зад чакъла, пред трибуната
            hoardings: false,
        });
    }

    return placements;
}

// ── Стълбчета и маршал ──────────────────────────────────────────────────

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
    const { xs, zs, nx, nz, count, spacing, halfWidths } = track;

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

            const offset = (halfWidths[i] + 2.5) * side;
            // Основата е вкопана 0.4 m във вержа — стълбче на наклонен банкет
            // иначе показва ръба на кутията си.
            matrix.setPosition(
                xs[i] + nx[i] * offset,
                vergeHeight(track, i, offset, 0.55 - 0.4),
                zs[i] + nz[i] * offset
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
    mesh.computeBoundingSphere();
    mesh.frustumCulled = true;

    return mesh;
}

/**
 * Маршал на банкета до стартовата линия. Вее кариран флаг на финалната
 * (летящата) обиколка — анимира се от Game.#frame чрез върнатия `flagPivot`.
 *
 * Процедурен, low-poly — без външен модел. Един е, затова няколкото меша са
 * без значение (локален, frustum-culled).
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
        new THREE.MeshStandardMaterial({ map: makeCheckeredTexture(8, 8), side: THREE.DoubleSide, roughness: 0.9 })
    );
    flag.position.y = 0.62;
    flagPivot.add(flag);

    group.add(flagPivot);

    // Точно до ръба на трасето (пред рекламния борд), малко след старт/финалната
    // линия, с лице към трасето — да се вижда ясно от минаващия болид. Стъпва
    // на вержа, не на нивото на асфалта.
    const i = Math.round(12 / track.spacing) % track.count;
    const off = track.halfWidths[i] + 1.4;
    group.position.set(
        track.xs[i] + track.nx[i] * off,
        vergeHeight(track, i, off),
        track.zs[i] + track.nz[i] * off
    );
    group.rotation.y = Math.atan2(-track.nx[i], -track.nz[i]);

    return { group, flagPivot };
}

// ── Процедурни текстури ─────────────────────────────────────────────────

/**
 * Кариран флаг / стартова лента — черно/бяло каре с дадени клетки.
 *
 * @param {number} columns
 * @param {number} rowsCount
 * @returns {THREE.CanvasTexture}
 */
function makeCheckeredTexture(columns, rowsCount) {
    const cell = 16;
    const canvas = document.createElement('canvas');
    canvas.width = columns * cell;
    canvas.height = rowsCount * cell;
    const ctx = canvas.getContext('2d');

    for (let y = 0; y < rowsCount; y++) {
        for (let x = 0; x < columns; x++) {
            ctx.fillStyle = (x + y) % 2 === 0 ? '#0a0a0a' : '#f4f4f4';
            ctx.fillRect(x * cell, y * cell, cell, cell);
        }
    }

    const texture = new THREE.CanvasTexture(canvas);
    texture.wrapS = THREE.RepeatWrapping;
    texture.wrapT = THREE.RepeatWrapping;
    texture.colorSpace = THREE.SRGBColorSpace;

    return texture;
}

/**
 * Процедурна текстура на информационните пана пред трибуната: цветни
 * блокове с неутрални състезателни фрази. Не чете конфигурационни марки,
 * затова този резервен път също не може да покаже продукт.
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
    const names = ['ИГРА', 'АПЕКС', 'ЧИСТА ЛИНИЯ', 'СЕКТОР', 'ПИТ ЛЕЙН'];
    let x = 0;
    let k = 0;
    while (x < w) {
        const bw = 96 + hashNoise(k * 5.3) * 32;
        ctx.fillStyle = colors[Math.floor(hashNoise(k * 1.7) * colors.length)];
        ctx.fillRect(x, 0, bw, h);
        ctx.strokeStyle = 'rgba(255,255,255,0.55)';
        ctx.lineWidth = 2;
        ctx.strokeRect(x + 6, 12, bw - 12, h - 24);
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 22px sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(names[k % names.length], x + bw / 2, h / 2, bw - 20);
        x += bw;
        k++;
    }

    const texture = new THREE.CanvasTexture(canvas);
    texture.wrapS = THREE.RepeatWrapping;
    texture.wrapT = THREE.RepeatWrapping;
    texture.colorSpace = THREE.SRGBColorSpace;

    return texture;
}

// ── Помощни ─────────────────────────────────────────────────────────────

const UP = new THREE.Vector3(0, 1, 0);

/** CSS запис на 0xRRGGBB. */
function cssColor(hex) {
    return '#' + hex.toString(16).padStart(6, '0');
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

/** Дробна част. */
function fract(v) {
    return v - Math.floor(v);
}

/**
 * @param {number} a
 * @param {number} b
 * @param {number} x
 * @returns {number}
 */
function smoothstep(a, b, x) {
    const t = Math.min(1, Math.max(0, (x - a) / (b - a)));

    return t * t * (3 - 2 * t);
}
