/**
 * Полуавтоматична трансмисия — само за оборотомера, индикатора на предавката и
 * звука. НЕ пипа физиката (там тягата е константна, виж physics.js): това е
 * визуално-звуков слой върху скоростта.
 *
 * По регламент на ФИА: 8 предавки напред + 1 задна, лимит на оборотите
 * 15 000 об/мин. Сменя автоматично нагоре при червената зона и надолу при
 * падане на оборотите (с хистерезис, за да не „лови" на границата).
 */

/** Лимит на оборотите (регламент на ФИА за турбо-хибридите). */
export const REDLINE = 15000;

/** Обороти на празен ход (F1 върти високо). */
const IDLE = 4000;

/** Прагове за автоматична смяна (хистерезис между тях спира трептенето). */
const UPSHIFT = 14600;
const DOWNSHIFT = 8500;

/**
 * Скорост (m/s), при която всяка предавка достига червената зона. 8-ма опира
 * максималната скорост на болида (~92 m/s ≈ 331 km/h). Прогресията е плавна.
 */
const GEAR_TOP = [15, 23, 32, 42, 53, 65, 78, 95];

/**
 * @typedef {object} Drivetrain
 * @property {number} gear   1..8 напред, 0 = заден (R)
 * @property {number} rpm    текущи обороти
 * @property {boolean} reverse
 */

/**
 * @returns {Drivetrain}
 */
export function createDrivetrain() {
    return { gear: 1, rpm: IDLE, reverse: false };
}

/**
 * Обороти за дадена скорост в дадена предавка.
 *
 * @param {number} speed  m/s (>= 0)
 * @param {number} gear   1..8
 * @returns {number}
 */
function rpmFor(speed, gear) {
    const top = GEAR_TOP[gear - 1] ?? GEAR_TOP[GEAR_TOP.length - 1];

    return IDLE + (speed / top) * (REDLINE - IDLE);
}

/**
 * Обновява оборотите + предавката от скоростта и газта. Мутира `dt`.
 *
 * @param {Drivetrain} dt
 * @param {number} vForward  надлъжна скорост, m/s (< 0 = заден ход)
 * @param {number} throttle  [0, 1]
 * @returns {Drivetrain}
 */
export function updateDrivetrain(dt, vForward, throttle) {
    // Заден ход.
    if (vForward < -0.3) {
        dt.reverse = true;
        dt.gear = 0;
        const frac = Math.min(1, Math.abs(vForward) / 12);
        dt.rpm = IDLE + frac * (REDLINE - IDLE) * 0.6;

        return dt;
    }

    dt.reverse = false;
    const speed = vForward > 0 ? vForward : 0;

    if (speed < 2) {
        // Почти в покой: оборотите следват газта (двигателят се върти без движение).
        dt.gear = 1;
        dt.rpm = clamp(IDLE + throttle * (REDLINE - IDLE) * 0.45, IDLE, REDLINE);

        return dt;
    }

    let rpm = rpmFor(speed, dt.gear);

    if (rpm > UPSHIFT && dt.gear < 8) {
        dt.gear++;
        rpm = rpmFor(speed, dt.gear);
    } else if (rpm < DOWNSHIFT && dt.gear > 1) {
        dt.gear--;
        rpm = rpmFor(speed, dt.gear);
    }

    dt.rpm = clamp(rpm, IDLE, REDLINE);

    return dt;
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
