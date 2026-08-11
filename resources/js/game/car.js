/**
 * Процедурен low-poly болид.
 *
 * Няма външен модел — всичко е от примитиви. Освен че спестява асети, това
 * държи силуета генеричен: без ливрея и без емблеми на съществуващ отбор.
 */

import * as THREE from 'three';

const BODY_COLOR = 0xd42a26;
const DARK = 0x1b1b1f;
const ACCENT = 0xf2f2f2;
const TYRE = 0x18181b;

/** Максимален визуален наклон настрани, радиани. */
const MAX_ROLL = 0.055;

/** Максимален визуален наклон напред/назад, радиани. */
const MAX_PITCH = 0.035;

/**
 * @typedef {object} CarRig
 * @property {THREE.Group} root   Позиция и посока на колата
 * @property {THREE.Group} body   Вътрешна група за наклоните
 * @property {THREE.Object3D[]} frontWheels
 * @property {THREE.Object3D[]} allWheels
 */

/**
 * Сглобява болида. Локално гледа по +Z.
 *
 * @returns {CarRig}
 */
export function buildCar() {
    const root = new THREE.Group();
    // Пичът за наклона трябва да е около ЛОКАЛНАТА напречна ос на колата, не
    // около световната X. При default ред 'XYZ' heading се прилага преди пича,
    // та на склон + завой (heading ≠ 0) пичът частично става роул и предницата
    // хлътва в асфалта. 'YXZ' прилага пича локално, после heading.
    root.rotation.order = 'YXZ';
    const body = new THREE.Group();
    root.add(body);

    // PBR: боята е металик с clearcoat (заотразява небето от env map-а);
    // гумите — матови; тъмните части — леко гланцирани.
    const paint = new THREE.MeshPhysicalMaterial({
        color: BODY_COLOR,
        metalness: 0.55,
        roughness: 0.32,
        clearcoat: 1.0,
        clearcoatRoughness: 0.12,
    });
    const dark = new THREE.MeshStandardMaterial({ color: DARK, metalness: 0.35, roughness: 0.5 });
    const accent = new THREE.MeshStandardMaterial({ color: ACCENT, metalness: 0.2, roughness: 0.45 });
    const rubber = new THREE.MeshStandardMaterial({ color: TYRE, metalness: 0.0, roughness: 0.88 });

    /**
     * @param {THREE.BufferGeometry} geometry
     * @param {THREE.Material} material
     * @param {[number, number, number]} position
     * @returns {THREE.Mesh}
     */
    const part = (geometry, material, position) => {
        const mesh = new THREE.Mesh(geometry, material);
        mesh.position.set(...position);
        body.add(mesh);

        return mesh;
    };

    // Основно шаси — стеснява се към носа.
    const chassis = new THREE.BoxGeometry(0.62, 0.30, 2.6);
    part(chassis, paint, [0, 0.34, -0.10]);

    // Нос: клин, получен чрез свиване на предните върхове на кутия.
    const nose = new THREE.BoxGeometry(0.42, 0.20, 1.9);
    taperGeometry(nose, 'z', 1.9, 0.30);
    part(nose, paint, [0, 0.30, 1.75]);

    // Странични понтони.
    for (const side of [-1, 1]) {
        const pod = new THREE.BoxGeometry(0.34, 0.34, 1.5);
        taperGeometry(pod, 'z', 1.5, 0.55);
        part(pod, paint, [side * 0.52, 0.32, -0.25]);
    }

    // Преден спойлер.
    part(new THREE.BoxGeometry(1.85, 0.05, 0.42), accent, [0, 0.16, 2.55]);
    part(new THREE.BoxGeometry(1.85, 0.12, 0.05), paint, [0, 0.24, 2.40]);

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
    part(new THREE.BoxGeometry(0.46, 0.16, 0.72), dark, [0, 0.52, -0.10]);
    const halo = new THREE.Mesh(
        new THREE.TorusGeometry(0.32, 0.035, 6, 12, Math.PI),
        dark
    );
    halo.rotation.set(-Math.PI / 2, 0, 0);
    halo.position.set(0, 0.60, 0.16);
    body.add(halo);

    // Колела: цилиндри с ос по X.
    const frontWheels = [];
    const allWheels = [];

    for (const [dx, dz, radius, width] of [
        [-0.78, 1.55, 0.34, 0.30],
        [0.78, 1.55, 0.34, 0.30],
        [-0.82, -1.55, 0.37, 0.42],
        [0.82, -1.55, 0.37, 0.42],
    ]) {
        const geometry = new THREE.CylinderGeometry(radius, radius, width, 12);
        geometry.rotateZ(Math.PI / 2);

        // Предните колела висят в собствена група, за да се завъртат около
        // вертикалната си ос независимо от търкалянето.
        const pivot = new THREE.Group();
        pivot.position.set(dx, radius, dz);

        const wheel = new THREE.Mesh(geometry, rubber);
        pivot.add(wheel);
        body.add(pivot);

        allWheels.push(wheel);
        if (dz > 0) {
            frontWheels.push(pivot);
        }
    }

    return { root, body, frontWheels, allWheels };
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
 * Синхронизира визуалния болид със състоянието на физиката.
 *
 * Наклоните са чисто козметични, но без тях колата изглежда като плъзгаща се
 * по масата картонена кутия.
 *
 * @param {CarRig} rig
 * @param {import('./physics.js').CarState} state
 * @param {{height: number, gradient: number}} surface
 * @param {number} dt
 */
export function updateCarRig(rig, state, surface, dt) {
    rig.root.position.set(state.x, surface.height, state.z);
    rig.root.rotation.y = state.heading;

    // Носът следва склона, но ПЛАВНО: `gradient` е дискретна на всяка осева
    // точка и без изглаждане пичът подскача при всяко прекосяване (тресене,
    // което дразни окото). Знакът е обратен на наклона: при изкачване
    // (gradient > 0) предницата се вдига (отрицателна ротация около X при модел
    // по +Z).
    const slopePitch = -Math.atan(surface.gradient);
    rig.root.rotation.x += (slopePitch - rig.root.rotation.x) * (1 - Math.exp(-9 * dt));

    const speed = Math.abs(state.vForward);

    // Крен навън в завоя, пропорционален на страничното ускорение.
    const lateralAccel = state.yawRate * state.vForward;
    const targetRoll = clamp(-lateralAccel / 45, -1, 1) * MAX_ROLL;

    // Клякане отзад при ускорение, гмуркане отпред при спиране.
    const targetPitch = clamp(-state.vForward * 0.004 + state.slip * 0.2, -1, 1) * MAX_PITCH;

    const k = 1 - Math.exp(-8 * dt);
    rig.body.rotation.z += (targetRoll - rig.body.rotation.z) * k;
    rig.body.rotation.x += (targetPitch - rig.body.rotation.x) * k;

    // Завъртане на предните колела.
    const steerVisual = state.steer * 0.42;
    for (const pivot of rig.frontWheels) {
        pivot.rotation.y += (steerVisual - pivot.rotation.y) * k;
    }

    // Търкаляне. При висока скорост оборотите се насищат — иначе колелата
    // стават стробоскопично мигане и изглеждат сякаш се въртят назад.
    const spin = Math.min(speed, 30) * dt * 2.2;
    for (const wheel of rig.allWheels) {
        wheel.rotation.x += spin;
    }
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
