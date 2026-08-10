/**
 * Аркадна физика на болида, с фиксирана стъпка.
 *
 * Съзнателно НЕ е Pacejka/пълен bicycle модел: на клавиатура пълният модел е
 * непредвидим и неприятен. Този е прост и настройваем, но пази двете неща,
 * които правят усещането „формула" — сцепление, растящо със скоростта
 * (downforce), и брутално спиране.
 *
 * `step()` е чиста функция на (състояние, вход, dt) без достъп до времето или
 * случайност. Това е нарочно: същият код може да превърти записан вход на
 * сървъра при валидация на класацията.
 */

/** Стъпка на симулацията. Рендерът интерполира между стъпките. */
export const FIXED_DT = 1 / 120;

/**
 * Параметри на болида. Стойностите са в SI (метри, секунди, радиани).
 */
export const CAR = {
    /** Таван на скоростта, m/s. Реалната максимална е под него — определя я
     *  равновесието между тягата и съпротивлението (≈ 88 m/s, 317 km/h). */
    maxSpeed: 92,

    /** Ускорение при нулева скорост и пълна газ, m/s² (0-100 km/h за ~2.3 s). */
    enginePower: 13,

    /** Забавяне при пълна спирачка, m/s² (≈ 4.6 g). */
    brakePower: 45,

    /** Забавяне при заден ход, m/s². */
    reversePower: 6,

    /** Максимална скорост на заден ход, m/s. */
    maxReverseSpeed: 12,

    /**
     * Аеродинамично съпротивление (квадратично). Заедно с enginePower
     * определя максималната скорост: P = drag·v² + roll·v.
     */
    drag: 0.00145,

    /** Съпротивление при търкаляне (линейно по скоростта). */
    rollingResistance: 0.02,

    /** Странично сцепление при нулева скорост, m/s² (≈ 1.5 g). */
    baseGrip: 15,

    /** Прираст на сцеплението от притискащата сила: grip += coef * v². */
    downforceCoef: 0.0063,

    /** Максимален ъгъл на предните колела, радиани. */
    maxSteerAngle: 0.52,

    /**
     * Колко бързо пада ъгълът на завиване със скоростта. Без това на 300 km/h
     * едно докосване на стрелката завърта колата на 90°.
     */
    steerSpeedFalloff: 0.055,

    /** Скорост на завъртане на волана, единици/сек. */
    steerRate: 3.4,

    /** Скорост на връщане на волана в центъра, единици/сек. */
    steerReturnRate: 5.5,

    /** Разстояние между осите, метри. */
    wheelbase: 3.6,

    /** Коефициент на сцепление извън трасето. */
    offTrackGripFactor: 0.38,

    /** Допълнително забавяне извън трасето, m/s². */
    offTrackDrag: 9.0,
};

/** Земно ускорение, m/s². */
const GRAVITY = 9.81;

/**
 * @typedef {object} CarState
 * @property {number} x
 * @property {number} z
 * @property {number} heading      Радиани; forward = (sin h, cos h)
 * @property {number} vForward     Надлъжна скорост, m/s
 * @property {number} vLateral     Странична скорост, m/s (+ = надясно)
 * @property {number} steer        Текущо положение на волана, [-1, 1]
 * @property {number} yawRate      rad/s, само за визуализация
 * @property {number} slip         0..1, колко плъзга — за ефекти
 */

/**
 * @typedef {object} CarInput
 * @property {number} throttle  [0, 1]
 * @property {number} brake     [0, 1]
 * @property {number} steer     [-1, 1] желана посока
 */

/**
 * Начално състояние на стартовата линия.
 *
 * @param {import('./track.js').Track} track
 * @returns {CarState}
 */
export function createCarState(track) {
    return {
        x: track.xs[0],
        z: track.zs[0],
        heading: Math.atan2(track.tx[0], track.tz[0]),
        vForward: 0,
        vLateral: 0,
        steer: 0,
        yawRate: 0,
        slip: 0,
    };
}

/**
 * Една стъпка на симулацията. Мутира `state`.
 *
 * @param {CarState} state
 * @param {CarInput} input
 * @param {number} dt
 * @param {boolean} onTrack Дали колата е върху асфалта
 * @param {number} gradient Наклон на трасето по посоката на движение (dy/ds)
 */
export function step(state, input, dt, onTrack, gradient = 0) {
    const gripFactor = onTrack ? 1 : CAR.offTrackGripFactor;

    // ── Волан ────────────────────────────────────────────────────────────
    // Воланът се движи с крайна скорост, не мигновено. На клавиатура това е
    // разликата между „кола" и „курсор": без него всяко натискане е удар.
    const target = clamp(input.steer, -1, 1);
    if (Math.abs(target) > 0.01) {
        const rate = CAR.steerRate * dt;
        state.steer += clamp(target - state.steer, -rate, rate);
    } else {
        const rate = CAR.steerReturnRate * dt;
        state.steer -= clamp(state.steer, -rate, rate);
    }
    state.steer = clamp(state.steer, -1, 1);

    // ── Надлъжна динамика ────────────────────────────────────────────────
    let accel = 0;

    if (input.throttle > 0 && state.vForward < CAR.maxSpeed) {
        // Тягата е константна; максималната скорост идва от равновесието със
        // съпротивлението по-долу. По-честно от изкуствено гасене на мощността
        // и дава сама по себе си правдоподобна крива на ускорението.
        accel += CAR.enginePower * input.throttle * gripFactor;
    }

    if (input.brake > 0) {
        if (state.vForward > 0.5) {
            accel -= CAR.brakePower * input.brake * gripFactor;
        } else if (state.vForward > -CAR.maxReverseSpeed) {
            // Под прага спирачката става заден ход.
            accel -= CAR.reversePower * input.brake;
        }
    }

    accel -= CAR.drag * state.vForward * Math.abs(state.vForward);
    accel -= CAR.rollingResistance * state.vForward * (onTrack ? 1 : 3);

    if (!onTrack) {
        accel -= Math.sign(state.vForward) * CAR.offTrackDrag;
    }

    // Съставяща на тежестта по склона. `gradient` е тангенсът на наклона, а на
    // нас ни трябва синусът — при 18% (Ео Руж) разликата е 1.6%, но е евтина.
    if (gradient !== 0) {
        accel -= GRAVITY * (gradient / Math.sqrt(1 + gradient * gradient));
    }

    state.vForward += accel * dt;

    // Спирачката не бива да тласка колата назад в рамките на една стъпка.
    if (input.brake > 0 && input.throttle === 0 && Math.abs(state.vForward) < 0.3) {
        state.vForward = 0;
    }

    state.vForward = clamp(state.vForward, -CAR.maxReverseSpeed, CAR.maxSpeed);

    // ── Сцепление и завиване ─────────────────────────────────────────────
    const speed = Math.abs(state.vForward);
    const maxLateralAccel =
        (CAR.baseGrip + CAR.downforceCoef * speed * speed) * gripFactor;

    const steerAngle =
        CAR.maxSteerAngle * state.steer / (1 + speed * CAR.steerSpeedFalloff);

    const desiredYawRate = (state.vForward * Math.tan(steerAngle)) / CAR.wheelbase;

    // Центростремителното ускорение не може да надвиши сцеплението: при опит
    // колата отива в подуправление вместо да завие.
    const maxYawRate = maxLateralAccel / Math.max(speed, 1);
    const yawRate = clamp(desiredYawRate, -maxYawRate, maxYawRate);

    state.slip =
        Math.abs(desiredYawRate) > 1e-4
            ? clamp(1 - Math.abs(yawRate) / Math.abs(desiredYawRate), 0, 1)
            : 0;

    state.yawRate = yawRate;
    state.heading += yawRate * dt;

    // Частта от желаното завиване, която сцеплението не понесе, се превръща в
    // странично плъзгане — оттам идва усещането за „изнасяне" в завоя.
    const unservedYaw = desiredYawRate - yawRate;
    state.vLateral += unservedYaw * speed * dt;

    // Гумите гасят страничната скорост до лимита на сцеплението.
    const lateralFriction = maxLateralAccel * dt;
    if (Math.abs(state.vLateral) <= lateralFriction) {
        state.vLateral = 0;
    } else {
        state.vLateral -= Math.sign(state.vLateral) * lateralFriction;
    }

    // ── Интегриране на позицията ─────────────────────────────────────────
    const sin = Math.sin(state.heading);
    const cos = Math.cos(state.heading);

    // forward = (sin, cos); right = (cos, -sin)
    state.x += (state.vForward * sin + state.vLateral * cos) * dt;
    state.z += (state.vForward * cos - state.vLateral * sin) * dt;
}

/**
 * Скорост на колата в km/h, за HUD.
 *
 * @param {CarState} state
 * @returns {number}
 */
export function speedKmh(state) {
    return Math.abs(Math.hypot(state.vForward, state.vLateral)) * 3.6;
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
