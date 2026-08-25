/**
 * Аркадно-симулационна физика на болида, с фиксирана стъпка.
 *
 * Опростен двуосев (bicycle) модел: предната и задната ос имат отделни странични
 * сили с таван по хватката си. Когато задната се насити — на входа на завой
 * (прехвърляне на тегло към предницата при спиране) или от газта на изход —
 * колата ротира от задницата, контролируем oversteer като в реалния F1.
 * Настроен за клавиатура: ротацията е умерена, не аркаден дрифт. Пази усещането
 * „формула" — сцепление, растящо със скоростта (downforce), и брутално спиране.
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

    /** Странично сцепление при нулева скорост, m/s². Вдигано на стъпки
     *  (22→25→27): с клавиатура повече базова хватка държи линията по-лесно. */
    baseGrip: 27,

    /** Прираст на сцеплението от притискащата сила: grip += coef * v².
     *  Вдиган (0.0082→0.0095→0.011) за повече хватка в бързите завои. */
    downforceCoef: 0.011,

    /** Максимален ъгъл на предните колела, радиани. Вдигнат от 0.52 —
     *  предницата „хапе" повече, колата не е дървена в бавните завои. */
    maxSteerAngle: 0.58,

    /**
     * Колко бързо пада ъгълът на завиване със скоростта. Без това на 300 km/h
     * едно докосване на стрелката завърта колата на 90°. Вдигано на стъпки
     * (0.045→0.055→0.075): на висока скорост предницата „играеше" твърде много и
     * колата беше нервна — сега воланът е осезаемо по-мек на скорост (по-малко
     * front bite в бързите завои), но бавните завои остават остри. Балансът
     * пази предизвикателството за класацията.
     */
    steerSpeedFalloff: 0.075,

    /** Скорост на завъртане на волана, единици/сек. Смекчена (6.5→5.0): на
     *  клавиатура твърде бързият волан прави входа рязък; 5.0 се модулира лесно. */
    steerRate: 5.0,

    /** Скорост на връщане на волана в центъра, единици/сек. */
    steerReturnRate: 5.5,

    /** Разстояние между осите, метри. */
    wheelbase: 3.6,

    // ── Двуосев модел (ротация на задницата) ─────────────────────────────

    /** Дял от теглото върху предната ос в покой (F1 ~46/54). Равно на b/L. */
    staticFrontLoad: 0.46,

    /** Височина на центъра на тежестта, метри. Ниска при F1 → малко прехвърляне
     *  на тегло, но достатъчно за ротация на входа на завоя. */
    cgHeight: 0.3,

    /** Таван на прехвърлянето на тегло (дял), за да не олекне ос напълно. */
    maxLoadTransfer: 0.3,

    /** Ъгъл на плъзгане (рад), при който оста достига тавана на хватката си.
     *  Предната е прогресивна (0.1) за предвидим вход. Задната вдигната
     *  (0.15→0.18): задницата поддава по-плавно → по-предвидим, лесен изход. */
    gripPeakSlipFront: 0.1,
    gripPeakSlipRear: 0.18,

    /** Мащаб на инерцията на въртене (Iz = m·a·b·factor). По-малко = по-остра
     *  ротация. Леко вдигнат (1.25→1.5) за по-плавно, по-предвидимо завъртане —
     *  подходящо за браузърна игра. */
    yawInertiaFactor: 1.35,

    /** Затихване на въртенето, 1/s. Вдигнато (3.8→4.2): плъзгането е по-лесно за
     *  „хващане" и колата е по-малко нервна, без да убива живостта. */
    yawDamping: 4.2,

    /** Пиково аеро-затихване на въртенето (при максимална скорост), 1/s. Расте
     *  кубично със скоростта, затова е почти нула в бавните/средните завои и
     *  силно на права/висока скорост — лека корекция там не изхвърля задницата.
     *  Вдигнато (8→11) за повече стабилност на скорост. */
    yawDampingAero: 11.0,

    /** Таван на ъгловата скорост, rad/s. */
    maxYawRate: 1.7,

    /** Колко газта „изяжда" задната странична хватка → power-oversteer на изход.
     *  Скалира се по скоростта (powerBite) — силно на изход от бавен завой,
     *  нула на правата. Смекчавано (0.55→0.5→0.4): по-малко завъртания на изход. */
    powerOversteer: 0.4,

    /** Праг на скоростта (m/s), под който караме кинематично (без ротация). */
    lowSpeedThreshold: 2.0,

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
 * @property {number} vLateral     Странична скорост, m/s. + е по локалната ос
 *                                 (cos h, -sin h) — след обръщането на z при
 *                                 зареждане (виж track.js) тя сочи СРЕЩУ
 *                                 нормалата на трасето, т.е. екранно наляво.
 *                                 Физиката е самосъгласувана; посоките на
 *                                 входа ги превежда Game.#readInput.
 * @property {number} steer        Текущо положение на волана, [-1, 1]
 * @property {number} yawRate      Ъглова скорост, rad/s (динамично състояние)
 * @property {number} slip         0..1, колко плъзга задницата — за ефекти
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
 * @param {boolean} onTrack Дали колата е върху асфалта (вкл. кербовете)
 * @param {number} gradient Наклон на трасето по посоката на движение (dy/ds)
 * @param {{gripFactor: number, drag: number}|null} offRoad Характер на
 *        повърхността ИЗВЪН трасето: чакълът дърпа много по-силно от тревата,
 *        асфалтовият апрон — почти никак. null = тревата по подразбиране.
 *        Стойностите идват детерминирано от данните на пистата, така че
 *        бъдещият сървърен replay остава възпроизводим.
 */
export function step(state, input, dt, onTrack, gradient = 0, offRoad = null) {
    const gripFactor = onTrack ? 1 : (offRoad?.gripFactor ?? CAR.offTrackGripFactor);

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
        accel -= Math.sign(state.vForward) * (offRoad?.drag ?? CAR.offTrackDrag);
    }

    // Съставяща на тежестта по склона. `gradient` е тангенсът на наклона, а на
    // нас ни трябва синусът — при 18% (Ео Руж) разликата е 1.6%, но е евтина.
    if (gradient !== 0) {
        accel -= GRAVITY * (gradient / Math.sqrt(1 + gradient * gradient));
    }

    // Надлъжното ускорение движи прехвърлянето на тегло (спирачка → отпред,
    // газта → отзад). Улавяме го преди клампването на скоростта.
    const axBody = accel;

    state.vForward += accel * dt;

    // Спирачката не бива да тласка колата назад в рамките на една стъпка.
    if (input.brake > 0 && input.throttle === 0 && Math.abs(state.vForward) < 0.3) {
        state.vForward = 0;
    }

    state.vForward = clamp(state.vForward, -CAR.maxReverseSpeed, CAR.maxSpeed);

    // ── Странична динамика: двуосев модел ────────────────────────────────
    // Отделни странични сили на предната и задната ос, всяка с таван по своята
    // хватка. Когато задната се насити (олекнала на входа при спиране или
    // „изядена" от газта на изход), колата ротира от задницата — контролируем
    // oversteer като в F1.
    const vx = state.vForward;
    const absV = Math.abs(vx);

    // Кормилен ъгъл на предните колела — с падане по скоростта, както преди.
    const steerAngle =
        (CAR.maxSteerAngle * state.steer) / (1 + absV * CAR.steerSpeedFalloff);

    if (absV < CAR.lowSpeedThreshold || vx < 0) {
        // Твърде бавно за смислени гуми-сили ИЛИ заден ход: караме кинематично.
        // Двуосевият модел ползва напредово приближение на slip ъглите, което на
        // заден ход обръща реакцията на волана — затова целият заден ход минава
        // оттук (kinYaw е коректен и за vx < 0). Гасим и остатъчното плъзгане.
        const kinYaw = (vx * Math.tan(steerAngle)) / CAR.wheelbase;
        state.yawRate = kinYaw;
        state.heading += kinYaw * dt;
        state.vLateral -= state.vLateral * Math.min(1, 10 * dt);
        state.slip = 0;
    } else {
        const grip = (CAR.baseGrip + CAR.downforceCoef * absV * absV) * gripFactor;

        // Геометрия: CG по-близо до задницата (staticFrontLoad = b/L).
        const a = CAR.wheelbase * (1 - CAR.staticFrontLoad); // CG → предна ос
        const b = CAR.wheelbase * CAR.staticFrontLoad; //        CG → задна ос

        // Надлъжно прехвърляне на тегло: спирачка (axBody < 0) товари предницата
        // и олекотява задницата → тя поддава на входа.
        const shift = clamp(
            (CAR.cgHeight / CAR.wheelbase) * (axBody / GRAVITY),
            -CAR.maxLoadTransfer,
            CAR.maxLoadTransfer
        );
        const loadF = clamp(CAR.staticFrontLoad - shift, 0.1, 0.9);
        const loadR = 1 - loadF;

        // Таван на страничното ускорение на всяка ос (сумата = grip при баланс).
        const gripF = grip * loadF;
        // Газта изяжда част от задната странична хватка (грип-кръг) → power-
        // oversteer на изход. Но само когато реално има теглителна сила: близо
        // до максималната скорост тягата е нищожна, затова НЕ бива да разхлабва
        // задницата — иначе лека корекция на правата „изхвърля" колата.
        const powerBite = Math.max(0, 1 - absV / CAR.maxSpeed);
        const rearUse = CAR.powerOversteer * input.throttle * powerBite;
        const gripR = grip * loadR * Math.max(0.25, 1 - rearUse * rearUse);

        // Ъгли на плъзгане на всяка ос (завъртени са само предните колела).
        const slipF = steerAngle - (state.vLateral + a * state.yawRate) / vx;
        const slipR = -(state.vLateral - b * state.yawRate) / vx;

        // Странични ускорения, наситени до тавана на всяка ос.
        const ayF = gripF * clamp(slipF / CAR.gripPeakSlipFront, -1, 1);
        const ayR = gripR * clamp(slipR / CAR.gripPeakSlipRear, -1, 1);

        // Въртене: моментът от двете оси минус затихване. Iz = m·a·b·factor,
        // затова масата се съкращава и работим в ускорения.
        const izz = a * b * CAR.yawInertiaFactor;
        // Затихването расте кубично със скоростта (аеро-стабилност) → правата е
        // спокойна, а бавните/средните завои остават живи.
        const speedRatio = absV / CAR.maxSpeed;
        const damping = CAR.yawDamping + CAR.yawDampingAero * speedRatio * speedRatio * speedRatio;
        const yawAccel = (a * ayF - b * ayR) / izz - damping * state.yawRate;
        state.yawRate = clamp(
            state.yawRate + yawAccel * dt,
            -CAR.maxYawRate,
            CAR.maxYawRate
        );

        // Странична скорост: сумата от осите минус центростремителната връзка.
        const latAccel = ayF + ayR - state.yawRate * vx;
        state.vLateral += latAccel * dt;
        // Не оставяме плъзгането да избяга извън разумното.
        state.vLateral = clamp(state.vLateral, -absV - 6, absV + 6);

        state.heading += state.yawRate * dt;

        // За визуализация: колко се плъзга задницата (0..1).
        state.slip = clamp(Math.abs(state.vLateral) / Math.max(absV, 1), 0, 1);
    }

    // ── Интегриране на позицията ─────────────────────────────────────────
    const sin = Math.sin(state.heading);
    const cos = Math.cos(state.heading);

    // forward = (sin, cos); страничната ос = (cos, -sin). В екранни/световни
    // термини тази ос е наляво (виж бележката при vLateral) — без значение за
    // самата симулация, стига Game.#readInput да превежда входа последователно.
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
