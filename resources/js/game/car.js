/**
 * Болидът: риг (позиция/наклони/колела) + два силуета.
 *
 * Истинската кола е външният GLB (public/game-models/car.glb, виж
 * carModel.js) — с runtime разделени колела, лак с маски и шлем. Процедурният
 * low-poly силует от примитиви (buildCar) е резервът, ако файлът липсва или
 * закъснее, LOD нивото на съперниците отдалеч и сенчестият заместител на
 * телефона. Той нарочно е генеричен: без ливрея и без емблеми на отбор.
 *
 * Рига е с ТРИ нива:
 *   root     — позиция, посока, наклон на платното (склон + банкинг)
 *   body     — окаченото тяло: крен/гмуркане/клякане/heave (дете на root)
 *   unsprung — колелата (дете на root, НЕ на body): те стоят на асфалта,
 *              тялото се люлее над тях, както при истинско окачване
 *
 * Всичко тук е презентация: чете състоянието на физиката, никога не го пише.
 */

import * as THREE from 'three';
import { mergeGeometries } from 'three/addons/utils/BufferGeometryUtils.js';
import { CAR } from './physics.js';
import {
    applyLiveryPatch,
    buildBlurDiscs,
    buildGlbCar,
    buildHelmet,
    cockpitPosition,
    loadCarTemplate,
    makeHologramMaterial,
} from './carModel.js';

export { buildDriverArms, loadCarTemplate } from './carModel.js';

const BODY_COLOR = 0xd42a26;
const DARK = 0x1b1b1f;
const ACCENT = 0xf2f2f2;
const TYRE = 0x18181b;
const PROCEDURAL_RIM = 0x6d7076;

/** Максимален визуален крен настрани от страничното ускорение, радиани. */
const MAX_ROLL = 0.018;

/** Наклон напред/назад от надлъжното ускорение, радиани. Твърдото F1
 *  окачване показва прехвърлянето на товар, без каросерията да се люлее. */
const MAX_PITCH_DYN = 0.014;

/** Старата скоростна формула — само без `dyn` (до интеграцията). */
const MAX_PITCH_LEGACY = 0.012;

/** Пружина на heave-а: собствена честота (rad/s) и затихване. */
const HEAVE_OMEGA = 18;
const HEAVE_ZETA = 0.95;

/** Асиметрично изглаждане на наклоните: компресия бърза, отпускане бавно (1/s). */
const DAMP_COMPRESS = 10;
const DAMP_REBOUND = 8;

/** Спирачно блокиране на предните колела: колко бързо ω → 0 (1/s). */
const LOCK_RATE = 25;

/** Ackermann усещане: вътрешното колело завива повече. */
const ACKERMANN_INNER = 1.15;

/** Над този |ω| (rad/s) blur дисковете са напълно плътни; под долния — скрити. */
const BLUR_OMEGA_MIN = 25;
const BLUR_OMEGA_MAX = 90;

/** Колко бавно се върти видимо колелото, когато дискът го „размазва" (rad/s). */
const BLUR_VISIBLE_SPIN = 8;

/** LOD на съперниците: под тази дистанция (m) се рисува GLB-то, над нея — процедурният. */
const OPPONENT_LOD_DISTANCE = 60;

const TWO_PI = Math.PI * 2;

/**
 * Процедурни оси — резервът за консуматорите (частици, следи, ефекти),
 * докато GLB-то не е пристигнало. Форма като rig.axles.
 */
export const CAR_AXLES_DEFAULT = Object.freeze({
    front: Object.freeze({ x: 0.78, y: 0.34, z: 1.55, radius: 0.34 }),
    rear: Object.freeze({ x: 0.82, y: 0.37, z: -1.55, radius: 0.37 }),
});

/** [dx, dz, radius, width] на процедурните колела; редът е детерминиран. */
const PROCEDURAL_WHEELS = [
    [-0.78, 1.55, 0.34, 0.3],
    [0.78, 1.55, 0.34, 0.3],
    [-0.82, -1.55, 0.37, 0.42],
    [0.82, -1.55, 0.37, 0.42],
];

/**
 * @typedef {import('./carModel.js').WheelRigEntry} WheelRigEntry
 * @typedef {import('./carModel.js').Axle} Axle
 * @typedef {import('./carModel.js').CarTemplate} CarTemplate
 */

/**
 * @typedef {object} CarDyn
 * Визуални сигнали за updateCarRig — всички по избор, само презентация.
 * @property {number} [gLong]    Изгладено надлъжно ускорение, m/s² (спиране < 0)
 * @property {number} [gLat]     Изгладено странично ускорение, m/s² (yawRate·v)
 * @property {number} [brake]    0..1
 * @property {number} [throttle] 0..1
 * @property {number} [rumble]   Вибрация от керба (m), добавя се към heave-а
 * @property {number} [lockF]    0..1 блокиране на предната ос (sim.state.out)
 * @property {number} [lockR]    0..1 блокиране на задната ос
 * @property {number} [spin]     0..1 буксуване на задната ос
 * @property {number} [kerbSide] -1|0|1 — на керб (импулс в heave-а при качване)
 */

/**
 * @typedef {object} CarRig
 * @property {THREE.Group} root       Позиция, посока и наклон на платното
 * @property {THREE.Group} body       Окаченото тяло (крен/пич/heave)
 * @property {THREE.Group} unsprung   Колелата — дете на root
 * @property {WheelRigEntry[]} wheels
 * @property {THREE.Object3D[]} frontWheels   Пивотите на завиване (съвместимост)
 * @property {THREE.Object3D[]} allWheels     Пивотите на търкалянето (съвместимост)
 * @property {{front: Axle, rear: Axle}} axles
 * @property {number} wheelRadius
 * @property {{front: number, rear: number}} tyreWidth
 * @property {THREE.Material[]} paintMaterials
 * @property {THREE.Material[]} wheelMaterials
 * @property {THREE.Group|null} model     Групата на GLB тялото (под body), null при процедурен
 * @property {THREE.Mesh|null} glbBody    Мешът на GLB тялото
 * @property {THREE.Group|null} helmet
 * @property {THREE.Object3D|null} shadowProxy
 * @property {(texture: THREE.Texture|null, rotation?: THREE.Euler|null) => void} setEnvironment
 * @property {(amount: number, color?: THREE.Color) => void} setDirt
 * @property {() => void} dispose   Освобождава собствените (не споделени) ресурси
 */

/**
 * Сглобява процедурния болид. Локално гледа по +Z.
 *
 * @returns {CarRig}
 */
export function buildCar() {
    const { root, body, unsprung } = buildRigFrame();

    // PBR: боята е металик с clearcoat (заотразява небето от env map-а);
    // гумите — матови; тъмните части — леко гланцирани.
    const materials = proceduralMaterials();

    for (const { geometry, material, position } of proceduralBodyParts(materials)) {
        const mesh = new THREE.Mesh(geometry, material);
        mesh.position.set(...position);
        if (geometry.userData.rotation) {
            mesh.rotation.set(...geometry.userData.rotation);
        }
        body.add(mesh);
    }

    const wheels = proceduralWheels(materials.rubber);
    for (const wheel of wheels) {
        unsprung.add(wheel.steer);
    }

    const rig = createRig(root, body, unsprung, wheels, {
        axles: { front: { ...CAR_AXLES_DEFAULT.front }, rear: { ...CAR_AXLES_DEFAULT.rear } },
        tyreWidth: { front: 0.3, rear: 0.42 },
        paintMaterials: [materials.paint],
        wheelMaterials: [materials.rubber],
    });

    return rig;
}

/**
 * Опитва да зареди външния GLB болид и да замести процедурния силует. Тихо
 * се отказва (остава процедурният), ако файлът липсва или не се зареди —
 * играта работи и без асета. Шаблонът се кешира между игрите (carModel.js).
 *
 * Успех: rig.wheels/frontWheels/allWheels/axles сочат към GLB колелата,
 * rig.model е тялото под rig.body, rig.helmet стои в кокпита, а
 * процедурните части са скрити (на телефон процедурното тяло остава като
 * невидим хвърлящ сянка заместител — 148k триъгълника в 512 shadow map са
 * най-скъпият ред на кадъра там, а сянката не показва детайла).
 *
 * @param {CarRig} rig
 * @param {() => boolean} [isStale]
 * @param {(fraction: number) => void} [onProgress] Прогрес на изтеглянето 0..1
 * @param {{environment?: THREE.Texture|null|(() => THREE.Texture|null), environmentRotation?: THREE.Euler|null|(() => THREE.Euler|null), maxAniso?: number, lowPower?: boolean, helmetColor?: number}} [options]
 *   environment/environmentRotation може да са ФУНКЦИИ — четат се в момента
 *   на закачането: HDRI PMREM-ът може да смени (и dispose-не) scene.environment
 *   докато GLB-то още се тегли, а хваната по-рано текстура би била мъртва.
 * @returns {Promise<void>}
 */
export function attachCarModel(rig, isStale, onProgress = () => {}, options = {}) {
    return new Promise((resolve) => {
        let settled = false;
        const done = () => {
            if (settled) {
                return;
            }
            settled = true;
            clearTimeout(timer);
            resolve();
        };
        // Тежък модел да не държи loading екрана безкрайно.
        const timer = setTimeout(done, 15000);

        loadCarTemplate(onProgress).then((template) => {
            // Късно (след старт) или след освобождаване — не показвай болида
            // (би бил pop). Шаблонът е споделен и остава за следващата игра.
            if (template === null || settled || isStale?.()) {
                done();
                return;
            }

            const lowPower = options.lowPower === true;
            const car = buildGlbCar(template, {
                environment: resolveOption(options.environment),
                environmentRotation: resolveOption(options.environmentRotation),
                maxAniso: options.maxAniso ?? 1,
                lowPower,
                castShadow: !lowPower,
            });

            // Скрий процедурните части — моделът ги замества визуално.
            // Светлинните ефекти (userData.carLight — спирачно греене, ауспух,
            // blur дискове) НЕ са част от силуета: при GLB, пристигнал късно,
            // те вече висят на body-то и скриването им би ги убило.
            for (const child of rig.body.children) {
                if (!child.userData.carLight) {
                    child.visible = false;
                }
            }
            for (const wheel of rig.wheels) {
                wheel.steer.visible = false;
            }

            rig.body.add(car.model);
            for (const wheel of car.wheels) {
                rig.unsprung.add(wheel.steer);
            }
            if (lowPower && !rig.shadowProxy) {
                rig.shadowProxy = buildCarShadowProxy();
                rig.body.add(rig.shadowProxy);
            }

            rig.model = car.model;
            rig.glbBody = car.body;
            rig.wheels = car.wheels;
            rig.frontWheels = car.wheels.filter((wheel) => wheel.axle === 'front').map((wheel) => wheel.steer);
            rig.allWheels = car.wheels.map((wheel) => wheel.spin);
            rig.axles = car.axles;
            rig.wheelRadius = car.wheelRadius;
            rig.tyreWidth = car.tyreWidth;
            rig.paintMaterials = car.paintMaterials;
            rig.wheelMaterials = car.wheelMaterials;

            rig.helmet = buildHelmet(options.helmetColor ?? ACCENT, cockpitPosition(template));
            rig.body.add(rig.helmet);

            done();
        });
    });
}

/**
 * Съперник върху споделения GLB шаблон: собствени материали с тонирана
 * ливрея (кръпка върху маската „боя"), LOD към процедурния силует над
 * OPPONENT_LOD_DISTANCE и невидим процедурен хвърлящ сянка (80k тела ×
 * 5 коли в shadow pass-а не си струват — сянката им е петно до колата).
 * Геометрията е споделена (userData.shared) — НЕ я dispose-вай при
 * разчистване на съперниците.
 *
 * @param {CarTemplate} template
 * @param {number} color
 * @param {{lowPower?: boolean, castShadow?: boolean, environment?: THREE.Texture|null, environmentRotation?: THREE.Euler|null, maxAniso?: number}} [options]
 * @returns {CarRig & {materials: THREE.Material[], livery: {uLivery: {value: THREE.Color}}, lods: THREE.LOD[]}}
 */
export function buildOpponentRigFromTemplate(template, color, options = {}) {
    const { root, body, unsprung } = buildRigFrame();
    const castShadow = options.castShadow ?? !options.lowPower;

    const car = buildGlbCar(template, {
        environment: options.environment ?? null,
        environmentRotation: options.environmentRotation ?? null,
        maxAniso: options.maxAniso ?? 1,
        lowPower: options.lowPower === true,
        castShadow: false,
    });
    const livery = applyLiveryPatch(car.paintMaterials[0], template, color);

    const far = buildMergedCar(color);
    for (const wheel of far.wheels) {
        // Далечното LOD ниво: върти се с останалите, но ефектите (дискове,
        // ореоли) не се закачат на него — над 60 m не се виждат.
        wheel.far = true;
    }

    const bodyLod = new THREE.LOD();
    bodyLod.addLevel(car.model, 0, 0.1);
    bodyLod.addLevel(far.body, OPPONENT_LOD_DISTANCE, 0.1);
    body.add(bodyLod);

    const nearWheels = new THREE.Group();
    for (const wheel of car.wheels) {
        nearWheels.add(wheel.steer);
    }
    const farWheels = new THREE.Group();
    for (const wheel of far.wheels) {
        farWheels.add(wheel.steer);
    }
    const wheelLod = new THREE.LOD();
    wheelLod.addLevel(nearWheels, 0, 0.1);
    wheelLod.addLevel(farWheels, OPPONENT_LOD_DISTANCE, 0.1);
    unsprung.add(wheelLod);

    const proxy = buildCarShadowProxy();
    proxy.castShadow = castShadow;
    body.add(proxy);

    const rig = createRig(root, body, unsprung, [...car.wheels, ...far.wheels], {
        axles: car.axles,
        tyreWidth: car.tyreWidth,
        paintMaterials: car.paintMaterials,
        wheelMaterials: car.wheelMaterials,
    });
    rig.model = car.model;
    rig.glbBody = car.body;
    rig.shadowProxy = proxy;
    rig.helmet = buildHelmet(color, cockpitPosition(template));
    rig.helmet.traverse((object) => {
        if (object.isMesh) {
            object.castShadow = false;
        }
    });
    car.model.add(rig.helmet);
    rig.materials = [...car.materials, ...far.materials];
    rig.livery = livery;
    rig.lods = [bodyLod, wheelLod];

    return rig;
}

/**
 * Духът върху GLB силуета: холограма (френел + сканлинии, адитивна), без
 * сянка, renderOrder 10 (след непрозрачното). Тонът се сменя с setTint;
 * tintables е празен за съвместимост със стария tintGhostRig.
 *
 * @param {CarTemplate} template
 * @param {number} tint
 * @returns {CarRig & {setTint: (color: number) => void, tintables: [], hologram: THREE.ShaderMaterial}}
 */
export function buildGhostRigFromTemplate(template, tint) {
    const { root, body, unsprung } = buildRigFrame();
    const hologram = makeHologramMaterial(tint);
    const car = buildGlbCar(template, { material: hologram, castShadow: false });

    body.add(car.model);
    for (const wheel of car.wheels) {
        unsprung.add(wheel.steer);
    }
    root.traverse((object) => {
        if (object.isMesh) {
            object.castShadow = false;
            object.receiveShadow = false;
            object.renderOrder = 10;
        }
    });

    const rig = createRig(root, body, unsprung, car.wheels, {
        axles: car.axles,
        tyreWidth: car.tyreWidth,
        paintMaterials: [],
        wheelMaterials: [],
    });
    rig.model = car.model;
    rig.hologram = hologram;
    rig.tintables = [];
    rig.setTint = (color) => {
        hologram.uniforms.uTint.value.set(color);
    };

    return rig;
}

/**
 * Процедурният болид, слят по материал: 3 меша тяло + 4 колела (7 вместо
 * 16 draw call-а) — LOD ниво на съперниците отдалеч и духът-силует.
 *
 * @param {number} [color]
 * @returns {{body: THREE.Group, wheels: WheelRigEntry[], materials: THREE.Material[]}}
 */
export function buildMergedCar(color = BODY_COLOR) {
    const materials = proceduralMaterials();
    materials.paint.color.set(color);
    const body = new THREE.Group();
    body.name = 'car-merged';

    for (const [material, geometry] of mergedBodyGeometries(materials)) {
        const mesh = new THREE.Mesh(geometry, material);
        mesh.castShadow = false;
        mesh.receiveShadow = true;
        body.add(mesh);
    }

    const wheels = proceduralWheels(materials.rubber, false);

    return { body, wheels, materials: [materials.paint, materials.dark, materials.accent, materials.rubber] };
}

/**
 * Невидим хвърлящ сянка: процедурното тяло + колела, слети в ЕДИН меш,
 * който не пише нито цвят, нито дълбочина (colorWrite/depthWrite false), но
 * влиза в shadow pass-а (той рисува с depth материал и не гледа маските).
 * Ефективен само със castShadow = true.
 *
 * @returns {THREE.Mesh}
 */
export function buildCarShadowProxy() {
    const materials = proceduralMaterials();
    const parts = mergedBodyGeometries(materials).map(([, geometry]) => geometry);
    for (const [dx, dz, radius, width] of PROCEDURAL_WHEELS) {
        const wheel = new THREE.CylinderGeometry(radius, radius, width, 8);
        wheel.rotateZ(Math.PI / 2);
        wheel.translate(dx, radius, dz);
        parts.push(wheel);
    }
    const geometry = mergeGeometries(parts, false);
    for (const part of parts) {
        part.dispose();
    }
    for (const material of Object.values(materials)) {
        material.dispose();
    }
    const mesh = new THREE.Mesh(
        geometry,
        new THREE.MeshBasicMaterial({ colorWrite: false, depthWrite: false, fog: false })
    );
    mesh.name = 'car-shadow-proxy';
    mesh.castShadow = true;
    mesh.receiveShadow = false;
    mesh.userData.carLight = true;

    return mesh;
}

/**
 * Синхронизира визуалния болид със състоянието на физиката.
 *
 * Наклоните са чисто козметични, но без тях колата изглежда като плъзгаща се
 * по масата картонена кутия. Със `dyn` (интегрираният път) пичът идва от
 * надлъжното ускорение (носът се гмурка при спиране, задницата кляка при
 * газ), кренът от страничното, heave-ът е пружина; без `dyn` важи старата
 * скоростна формула, за да работи Game до интеграцията.
 *
 * @param {CarRig} rig
 * @param {import('./physics.js').CarState & {out?: object}} state
 * @param {{height: number, gradient: number, bank?: number}} surface
 * @param {number} dt
 * @param {CarDyn|null} [dyn]
 */
export function updateCarRig(rig, state, surface, dt, dyn = null) {
    const root = rig.root;
    root.position.set(state.x, surface.height, state.z);
    root.rotation.y = state.heading;

    // Носът следва склона, но ПЛАВНО: `gradient` е дискретна на всяка осева
    // точка и без изглаждане пичът подскача при всяко прекосяване (тресене,
    // което дразни окото). Знакът е обратен на наклона: при изкачване
    // (gradient > 0) предницата се вдига (отрицателна ротация около X при модел
    // по +Z).
    const kSurface = 1 - Math.exp(-9 * dt);
    const slopePitch = -Math.atan(surface.gradient);
    root.rotation.x += (slopePitch - root.rotation.x) * kSurface;

    // Напречният наклон на платното (банкингът на Зандвоорт) е на ROOT, не на
    // body: колелата стоят на асфалта и лягат с него, тялото се люлее отгоре.
    // Знакът: bank > 0 сваля страната на нормалата (+x при heading 0), т.е.
    // положителна ротация около +Z за модел по +Z — визуално изравнено с
    // платното от ribbonMesh (y -= offset·bank).
    const bankRoll = Math.atan(surface.bank ?? 0);
    root.rotation.z += (bankRoll - root.rotation.z) * kSurface;

    const v = state.vForward;
    const speed = Math.abs(v);
    const out = state.out;
    const gLat = dyn?.gLat ?? state.yawRate * v;

    let targetRoll;
    let targetPitch;
    let heaveTarget;
    if (dyn) {
        const gLong = dyn.gLong ?? 0;
        // Крен навън в завоя; гмуркане при спиране (gLong < 0 → положителна
        // ротация около X = нос надолу); лек аеро-приклек със скоростта.
        targetRoll = clamp(-gLat / 40, -1, 1) * MAX_ROLL;
        targetPitch = clamp(-gLong / 40, -1, 1) * MAX_PITCH_DYN - (v / 92) ** 2 * 0.006;
        heaveTarget = -0.006 * (v / 92) ** 2 - (0.002 * Math.abs(gLong)) / 45;
    } else {
        targetRoll = clamp(-gLat / 45, -1, 1) * MAX_ROLL;
        targetPitch = clamp(-v * 0.004 + state.slip * 0.2, -1, 1) * MAX_PITCH_LEGACY;
        heaveTarget = 0;
    }

    // Асиметрични демпфери: към по-голям наклон (компресия) бързо, обратно
    // (отпускане) бавно — както истинско окачване.
    const body = rig.body;
    body.rotation.z += (targetRoll - body.rotation.z) * damperGain(targetRoll, body.rotation.z, dt);
    body.rotation.x += (targetPitch - body.rotation.x) * damperGain(targetPitch, body.rotation.x, dt);

    // Heave: пружина (ωn 14 rad/s, ζ 0.4) към целевата височина, кербът
    // добавя вибрация, качването на керб — импулс. Полу-имплицитен Ойлер с
    // таван на dt (стабилен при ωn·dt < 2).
    const dynState = rig._dyn;
    const hdt = Math.min(dt, 1 / 30);
    const rumble = dyn?.rumble ?? 0;
    const kerb = (dyn?.kerbSide ?? 0) !== 0 || rumble !== 0;
    if (kerb && !dynState.onKerb) {
        dynState.heaveVel += 0.012;
    }
    dynState.onKerb = kerb;
    const accel = HEAVE_OMEGA * HEAVE_OMEGA * (heaveTarget - dynState.heave) - 2 * HEAVE_ZETA * HEAVE_OMEGA * dynState.heaveVel;
    dynState.heaveVel += accel * hdt;
    dynState.heave += dynState.heaveVel * hdt;
    body.position.y = dynState.heave + rumble * 0.35;

    // Блокиране/буксуване: от sim.state.out (sim-v3), иначе от dyn, иначе
    // евристика по входа (спирачка + бързо + силно забавяне ≈ блокиране).
    const brake = dyn?.brake ?? 0;
    const throttle = dyn?.throttle ?? 0;
    const gLongForLock = dyn?.gLong ?? 0;
    const lockF =
        dyn?.lockF ?? out?.lockF ?? (brake > 0.85 && speed > 35 && gLongForLock < -30 ? 1 : 0);
    const lockR = dyn?.lockR ?? out?.lockR ?? 0;
    const spinR = dyn?.spin ?? out?.spin ?? (throttle > 0.9 && speed < 12 ? throttle : 0);

    // Завиване: същата формула като физиката (CAR се чете, не се пише) —
    // на 80 m/s истинският ъгъл е ~5°, не 24°.
    const steerAngle = (CAR.maxSteerAngle * state.steer) / (1 + speed * CAR.steerSpeedFalloff);
    const kSteer = 1 - Math.exp(-8 * dt);
    const kLock = 1 - Math.exp(-LOCK_RATE * dt);

    for (const wheel of rig.wheels) {
        // Търкаляне: ω = v / r. Знакът: положителна ротация около +X носи
        // върха на колелото към +Z — напред. Без таван на ω (старият 30 m/s
        // праг спираше джантите): стробоскопът се крие от blur дисковете.
        let omega = v / wheel.radius;
        const front = wheel.axle === 'front';
        const lock = front ? lockF : lockR;
        wheel.lockBlend += ((lock > 0.3 ? 1 : 0) - wheel.lockBlend) * kLock;
        omega *= 1 - wheel.lockBlend;
        if (!front) {
            omega *= 1 + 3 * spinR;
        }

        const blur = smoothstep(BLUR_OMEGA_MIN, BLUR_OMEGA_MAX, Math.abs(omega));
        // Над ~половин blur видимото въртене се забавя до бавно „клатене":
        // окото не го различава под диска, а стробоскопът изчезва.
        const visible = lerp(omega, Math.sign(omega) * BLUR_VISIBLE_SPIN, smoothstep(0.35, 0.65, blur));
        wheel.spin.rotation.x = (wheel.spin.rotation.x + visible * dt) % TWO_PI;

        // Скрити под прага: прозрачен меш с opacity 0 пак е draw call
        // (8 на кола на стоп — ×6 в състезание).
        const discOpacity = blur * 0.7;
        const discVisible = discOpacity > 0.01;
        for (const disc of wheel.discs) {
            disc.material.opacity = discOpacity;
            disc.visible = discVisible;
        }

        if (front) {
            // Вътрешното колело (страната, към която се завива: steer > 0
            // върти носа към +X) завива повече — Ackermann.
            const inner = wheel.side * state.steer > 0;
            const target = steerAngle * (inner ? ACKERMANN_INNER : 1);
            wheel.steer.rotation.y += (target - wheel.steer.rotation.y) * kSteer;
        }
    }

    // Шлемът се накланя навън в завоя (инерцията на главата).
    if (rig.helmet) {
        const lean = clamp(gLat / 40, -1, 1) * 0.12;
        rig.helmet.rotation.z += (lean - rig.helmet.rotation.z) * kSteer;
    }

    if (rig.hologram) {
        rig.hologram.uniforms.uTime.value = (rig.hologram.uniforms.uTime.value + dt) % 1000;
    }
}

// ── Вътрешни ─────────────────────────────────────────────────────────────

/**
 * Трите нива на рига (виж заглавния коментар).
 *
 * @returns {{root: THREE.Group, body: THREE.Group, unsprung: THREE.Group}}
 */
function buildRigFrame() {
    const root = new THREE.Group();
    // Пичът за наклона трябва да е около ЛОКАЛНАТА напречна ос на колата, не
    // около световната X. При default ред 'XYZ' heading се прилага преди пича,
    // та на склон + завой (heading ≠ 0) пичът частично става роул и предницата
    // хлътва в асфалта. 'YXZ' прилага пича локално, после heading, после
    // банкинга около локалната надлъжна ос.
    root.rotation.order = 'YXZ';
    const body = new THREE.Group();
    body.name = 'car-body-sprung';
    const unsprung = new THREE.Group();
    unsprung.name = 'car-unsprung';
    root.add(body);
    root.add(unsprung);

    return { root, body, unsprung };
}

/**
 * @param {THREE.Group} root
 * @param {THREE.Group} body
 * @param {THREE.Group} unsprung
 * @param {WheelRigEntry[]} wheels
 * @param {{axles: {front: Axle, rear: Axle}, tyreWidth: {front: number, rear: number}, paintMaterials: THREE.Material[], wheelMaterials: THREE.Material[]}} fields
 * @returns {CarRig}
 */
function createRig(root, body, unsprung, wheels, fields) {
    const white = new THREE.Color(0xffffff);
    const rig = {
        root,
        body,
        unsprung,
        wheels,
        frontWheels: wheels.filter((wheel) => wheel.axle === 'front').map((wheel) => wheel.steer),
        allWheels: wheels.map((wheel) => wheel.spin),
        axles: fields.axles,
        wheelRadius: (fields.axles.front.radius + fields.axles.rear.radius) / 2,
        tyreWidth: fields.tyreWidth,
        paintMaterials: fields.paintMaterials,
        wheelMaterials: fields.wheelMaterials,
        model: null,
        glbBody: null,
        helmet: null,
        shadowProxy: null,
        hologram: null,
        dirtUniforms: null,
        _dyn: { heave: 0, heaveVel: 0, onKerb: false },

        /**
         * Явен envMap на материалите на колата (three r180 чете
         * envMapIntensity на материала само при явен envMap). Викай след
         * смяната на HDRI PMREM-а — заедно със scene.environmentRotation:
         * при явен envMap three ползва material.envMapRotation, не тази на
         * сцената (WebGLRenderer r180:2055), а HDRI-то е завъртяно, за да
         * съвпадне запеченото му слънце с аналитичното.
         *
         * @param {THREE.Texture|null} texture
         * @param {THREE.Euler|null} [rotation]  scene.environmentRotation
         */
        setEnvironment(texture, rotation = null) {
            for (const material of [...rig.paintMaterials, ...rig.wheelMaterials]) {
                if (!('envMap' in material)) {
                    continue;
                }
                const hadEnv = material.envMap !== null;
                material.envMap = texture;
                if (rotation) {
                    material.envMapRotation.copy(rotation);
                }
                if (hadEnv !== (texture !== null)) {
                    material.needsUpdate = true; // USE_ENVMAP define-ът се сменя
                }
            }
        },

        /**
         * Замърсяване: гумите потъмняват към цвета на настилката; тялото го
         * поема dirt кръпката на carEffects (rig.dirtUniforms), ако е инсталирана.
         *
         * @param {number} amount 0..1
         * @param {THREE.Color} [color]
         */
        setDirt(amount, color = null) {
            const dirt = clamp(amount, 0, 1);
            for (const material of rig.wheelMaterials) {
                if (material.color) {
                    material.color.copy(white);
                    if (color) {
                        material.color.lerp(color, dirt * 0.45);
                    }
                }
            }
            if (rig.dirtUniforms) {
                rig.dirtUniforms.uDirt.value = dirt;
                if (color) {
                    rig.dirtUniforms.uDirtColor.value.copy(color);
                }
            }
        },

        /** Освобождава несподелените ресурси (споделените: userData.shared). */
        dispose() {
            root.traverse((object) => {
                if (!object.isMesh) {
                    return;
                }
                if (!object.geometry.userData.shared) {
                    object.geometry.dispose();
                }
                const materials = Array.isArray(object.material) ? object.material : [object.material];
                for (const material of materials) {
                    material.dispose();
                }
            });
        },
    };

    return rig;
}

/**
 * @returns {{paint: THREE.MeshPhysicalMaterial, dark: THREE.MeshStandardMaterial, accent: THREE.MeshStandardMaterial, rubber: THREE.MeshStandardMaterial}}
 */
function proceduralMaterials() {
    return {
        paint: new THREE.MeshPhysicalMaterial({
            color: BODY_COLOR,
            metalness: 0.55,
            roughness: 0.4,
            clearcoat: 1.0,
            clearcoatRoughness: 0.22,
        }),
        dark: new THREE.MeshStandardMaterial({ color: DARK, metalness: 0.35, roughness: 0.5 }),
        accent: new THREE.MeshStandardMaterial({ color: ACCENT, metalness: 0.2, roughness: 0.45 }),
        rubber: new THREE.MeshStandardMaterial({ color: TYRE, metalness: 0.0, roughness: 0.88 }),
    };
}

/**
 * Частите на процедурното тяло в детерминиран ред (Game споделя геометрии
 * между съперниците по реда на обхождане).
 *
 * @param {ReturnType<typeof proceduralMaterials>} materials
 * @returns {Array<{geometry: THREE.BufferGeometry, material: THREE.Material, position: [number, number, number]}>}
 */
function proceduralBodyParts(materials) {
    const { paint, dark, accent } = materials;
    const parts = [];
    const part = (geometry, material, position) => {
        parts.push({ geometry, material, position });
    };

    // Основно шаси — стеснява се към носа.
    part(new THREE.BoxGeometry(0.62, 0.3, 2.6), paint, [0, 0.34, -0.1]);

    // Нос: клин, получен чрез свиване на предните върхове на кутия.
    const nose = new THREE.BoxGeometry(0.42, 0.2, 1.9);
    taperGeometry(nose, 'z', 1.9, 0.3);
    part(nose, paint, [0, 0.3, 1.75]);

    // Странични понтони.
    for (const side of [-1, 1]) {
        const pod = new THREE.BoxGeometry(0.34, 0.34, 1.5);
        taperGeometry(pod, 'z', 1.5, 0.55);
        part(pod, paint, [side * 0.52, 0.32, -0.25]);
    }

    // Преден спойлер.
    part(new THREE.BoxGeometry(1.85, 0.05, 0.42), accent, [0, 0.16, 2.55]);
    part(new THREE.BoxGeometry(1.85, 0.12, 0.05), paint, [0, 0.24, 2.4]);

    // Заден спойлер и стойките му.
    part(new THREE.BoxGeometry(1.05, 0.04, 0.34), accent, [0, 0.92, -2.05]);
    for (const side of [-1, 1]) {
        part(new THREE.BoxGeometry(0.05, 0.55, 0.22), dark, [side * 0.42, 0.65, -2.02]);
    }

    // Въздухозаборник над пилота.
    const airbox = new THREE.BoxGeometry(0.34, 0.42, 0.62);
    taperGeometry(airbox, 'z', 0.62, 0.55);
    part(airbox, paint, [0, 0.66, -0.95]);

    // Кокпит и halo.
    part(new THREE.BoxGeometry(0.46, 0.16, 0.72), dark, [0, 0.52, -0.1]);
    const halo = new THREE.TorusGeometry(0.32, 0.035, 6, 12, Math.PI);
    halo.userData.rotation = [-Math.PI / 2, 0, 0];
    part(halo, dark, [0, 0.6, 0.16]);

    return parts;
}

/**
 * Тялото, запечено и слято по материал → [material, geometry] по ред
 * paint/dark/accent.
 *
 * @param {ReturnType<typeof proceduralMaterials>} materials
 * @returns {Array<[THREE.Material, THREE.BufferGeometry]>}
 */
function mergedBodyGeometries(materials) {
    const buckets = new Map();
    for (const { geometry, material, position } of proceduralBodyParts(materials)) {
        if (geometry.userData.rotation) {
            const [x, y, z] = geometry.userData.rotation;
            geometry.rotateX(x);
            geometry.rotateY(y);
            geometry.rotateZ(z);
        }
        geometry.translate(...position);
        if (!buckets.has(material)) {
            buckets.set(material, []);
        }
        buckets.get(material).push(geometry);
    }

    const merged = [];
    for (const [material, geometries] of buckets) {
        const geometry = mergeGeometries(geometries, false);
        for (const part of geometries) {
            part.dispose();
        }
        merged.push([material, geometry]);
    }

    return merged;
}

/**
 * Четирите процедурни колела: steer пивот в главината → spin пивот → цилиндър,
 * плюс blur дискове.
 *
 * @param {THREE.Material} rubber
 * @param {boolean} [withDiscs]
 * @returns {WheelRigEntry[]}
 */
function proceduralWheels(rubber, withDiscs = true) {
    const rimColor = new THREE.Color(PROCEDURAL_RIM);

    return PROCEDURAL_WHEELS.map(([dx, dz, radius, width]) => {
        const geometry = new THREE.CylinderGeometry(radius, radius, width, 12);
        geometry.rotateZ(Math.PI / 2);

        // Предните колела висят в собствена група, за да се завъртат около
        // вертикалната си ос независимо от търкалянето.
        const steer = new THREE.Group();
        steer.position.set(dx, radius, dz);
        const spin = new THREE.Group();
        steer.add(spin);
        const mesh = new THREE.Mesh(geometry, rubber);
        spin.add(mesh);

        const side = dx < 0 ? -1 : 1;
        const discs = withDiscs ? buildBlurDiscs({ radius, width, side, split: true }, rimColor) : [];
        for (const disc of discs) {
            steer.add(disc);
        }

        return { axle: dz > 0 ? 'front' : 'rear', side, steer, spin, mesh, radius, width, discs, lockBlend: 0 };
    });
}

/**
 * Свива единия край на кутия по дадена ос — евтин начин за клиновидни форми
 * без да се пише геометрия на ръка.
 *
 * @param {THREE.BufferGeometry} geometry
 * @param {'x'|'z'} axis      По коя ос е дължината
 * @param {number} length     Дължината на кутията по тази ос
 * @param {number} scale      Коефициент на свиване в положителния край
 */
function taperGeometry(geometry, axis, length, scale) {
    const position = geometry.attributes.position;
    const index = axis === 'z' ? 2 : 0;
    const half = length / 2;

    for (let i = 0; i < position.count; i++) {
        const along = position.getComponent(i, index);

        // t = 0 в задния край, 1 в предния.
        const t = (along + half) / length;
        const factor = 1 + (scale - 1) * t;

        position.setComponent(i, 0, position.getX(i) * (index === 0 ? 1 : factor));
        position.setComponent(i, 1, position.getY(i) * factor);

        if (index === 0) {
            position.setComponent(i, 2, position.getZ(i) * factor);
        } else {
            position.setComponent(i, 0, position.getX(i) * factor);
        }
    }

    position.needsUpdate = true;
    geometry.computeVertexNormals();
}

/**
 * Опция, която може да е стойност или функция, връщаща стойността сега.
 *
 * @template T
 * @param {T|(() => T)|undefined} option
 * @returns {T|null}
 */
function resolveOption(option) {
    if (typeof option === 'function') {
        return option() ?? null;
    }

    return option ?? null;
}

/**
 * Коефициент на изглаждане: бърз към по-голям наклон (компресия), бавен
 * обратно (отпускане).
 *
 * @param {number} target
 * @param {number} current
 * @param {number} dt
 * @returns {number}
 */
function damperGain(target, current, dt) {
    const compressing = Math.abs(target) > Math.abs(current);

    return 1 - Math.exp(-(compressing ? DAMP_COMPRESS : DAMP_REBOUND) * dt);
}

/**
 * @param {number} v
 * @param {number} min
 * @param {number} max
 * @returns {number}
 */
function clamp(v, min, max) {
    return v < min ? min : v > max ? max : v;
}

/**
 * @param {number} edge0
 * @param {number} edge1
 * @param {number} x
 * @returns {number}
 */
function smoothstep(edge0, edge1, x) {
    const t = clamp((x - edge0) / (edge1 - edge0), 0, 1);

    return t * t * (3 - 2 * t);
}

/**
 * @param {number} a
 * @param {number} b
 * @param {number} t
 * @returns {number}
 */
function lerp(a, b, t) {
    return a + (b - a) * t;
}
