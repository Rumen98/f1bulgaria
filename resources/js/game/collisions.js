/**
 * Контакт между колите в режим „Състезание": всяка кола е два кръга
 * (предница/задница) върху равнината на пистата. При застъпване телата се
 * разделят и получават импулс по нормалата + лек въртящ „ритник" при
 * офсетов удар — достатъчно за честно състезателно блъскане, без да е
 * тежка физика на твърди тела.
 *
 * ВАЖНО: щом контактът бута ИГРАЧА, времето му вече не е чиста функция от
 * неговия вход → сървърният реплей не може да го възпроизведе. Затова
 * колизиите живеят САМО в състезателния режим, чиито времена Vue НЕ праща
 * към класацията (onFinish гейт по competition флага).
 *
 * Известен компромис: удар по кола под lowSpeedThreshold (2 m/s) губи
 * въртящия ритник — кинематичният клон на physics.step презаписва yawRate.
 * Ниската скорост прави ефекта незабележим; не си струва специален случай.
 *
 * Чист модул без three.js/DOM — тества се в Node.
 */

/** Радиус на всеки от двата кръга на колата, м. */
const CIRCLE_RADIUS = 0.95;

/** Отстояние на кръговете от центъра по надлъжната ос, м. */
const HALF_LENGTH = 1.25;

/** Еластичност на удара: 0 = лепкав, 1 = билярд. Ниска — F1 не отскача. */
const RESTITUTION = 0.25;

/** Таван на скоростния импулс на един тик, m/s — срещу експлозии при
 *  дълбоко застъпване (телепорт, спавн). */
const MAX_IMPULSE = 6;

/** Въртящ ефект от офсетов удар, rad/s на m/s импулс. */
const YAW_KICK = 0.35;

/** Таван на въртящия ритник на един контакт, rad/s. */
const MAX_YAW_KICK = 0.9;

/**
 * Разрешава контактите между всички двойки коли. Мутира състоянията
 * (x, z, vForward, vLateral, yawRate) на място.
 *
 * @param {Array<import('./physics.js').CarState>} cars
 */
export function resolveCarContacts(cars) {
    for (let i = 0; i < cars.length; i++) {
        for (let j = i + 1; j < cars.length; j++) {
            resolvePair(cars[i], cars[j]);
        }
    }
}

/**
 * @param {import('./physics.js').CarState} a
 * @param {import('./physics.js').CarState} b
 */
function resolvePair(a, b) {
    // Бърз отказ: центровете са по-далеч от максималния обхват.
    const dcx = b.x - a.x;
    const dcz = b.z - a.z;
    const reach = 2 * (HALF_LENGTH + CIRCLE_RADIUS);
    if (dcx * dcx + dcz * dcz > reach * reach) {
        return;
    }

    const aSin = Math.sin(a.heading);
    const aCos = Math.cos(a.heading);
    const bSin = Math.sin(b.heading);
    const bCos = Math.cos(b.heading);

    // Най-дълбокото застъпване измежду 4-те двойки кръгове.
    let deepest = null;

    for (const sa of [1, -1]) {
        const ax = a.x + aSin * HALF_LENGTH * sa;
        const az = a.z + aCos * HALF_LENGTH * sa;

        for (const sb of [1, -1]) {
            const bx = b.x + bSin * HALF_LENGTH * sb;
            const bz = b.z + bCos * HALF_LENGTH * sb;

            const dx = bx - ax;
            const dz = bz - az;
            const distSq = dx * dx + dz * dz;
            const minDist = 2 * CIRCLE_RADIUS;

            if (distSq >= minDist * minDist) {
                continue;
            }

            const dist = Math.sqrt(distSq);
            const depth = minDist - dist;

            if (deepest === null || depth > deepest.depth) {
                // Нормала от A към B; при точно съвпадение — по оста на A.
                const nx = dist > 1e-6 ? dx / dist : aCos;
                const nz = dist > 1e-6 ? dz / dist : -aSin;
                deepest = { depth, nx, nz, sa, sb };
            }
        }
    }

    if (deepest === null) {
        return;
    }

    const { depth, nx, nz, sa, sb } = deepest;

    // ── Разделяне на позициите (50/50) ───────────────────────────────────
    const push = depth / 2;
    a.x -= nx * push;
    a.z -= nz * push;
    b.x += nx * push;
    b.z += nz * push;

    // ── Скоростен импулс по нормалата ────────────────────────────────────
    // Световни скорости от локалните (forward = (sin h, cos h), lateral е
    // по (cos h, -sin h) — виж physics.js).
    const avx = a.vForward * aSin + a.vLateral * aCos;
    const avz = a.vForward * aCos - a.vLateral * aSin;
    const bvx = b.vForward * bSin + b.vLateral * bCos;
    const bvz = b.vForward * bCos - b.vLateral * bSin;

    // Относителна скорост на B спрямо A по нормалата: < 0 = сближават се.
    const relN = (bvx - avx) * nx + (bvz - avz) * nz;

    if (relN < 0) {
        // Равни маси → импулсът се дели поравно.
        const impulse = Math.min(MAX_IMPULSE, (-(1 + RESTITUTION) * relN) / 2);

        const navx = avx - impulse * nx;
        const navz = avz - impulse * nz;
        const nbvx = bvx + impulse * nx;
        const nbvz = bvz + impulse * nz;

        // Обратно в локалните рамки.
        a.vForward = navx * aSin + navz * aCos;
        a.vLateral = navx * aCos - navz * aSin;
        b.vForward = nbvx * bSin + nbvz * bCos;
        b.vLateral = nbvx * bCos - nbvz * bSin;

        // ── Въртящ ритник при офсетов удар ───────────────────────────────
        // Ударен в предницата → носът се отмества по нормалата; в
        // задницата — обратно. Знакът идва от страничната компонента на
        // нормалата в рамката на всяка кола.
        const aSide = nx * aCos - nz * aSin; // нормалата странично за A
        const bSide = nx * bCos - nz * bSin;
        const kick = Math.min(MAX_YAW_KICK, impulse * YAW_KICK);

        a.yawRate += clamp(-sa * aSide * kick, -MAX_YAW_KICK, MAX_YAW_KICK);
        b.yawRate += clamp(sb * bSide * kick, -MAX_YAW_KICK, MAX_YAW_KICK);
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
