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
 * v3 (SIM_VERSION 3): гумата има ЛИМИТ и отвъд него — спад (рационална крива),
 * спирачките и газта делят хватката със завиването (кръг на триенето, по оси),
 * ABS/TC-лайт срещу 1-битовия вход, педални рампи, спиране с двигателя,
 * кербовете струват хватка и друсат, билата олекотяват, компресиите товарят,
 * банкингът тегли надолу, лек counter-steer асист. Всичко ново е с +−×/√ и
 * floor — без нови трансцендентни функции (виж бележката за детерминизма в
 * sim.js).
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

    /** Тяга при пълна газ, m/s² (0-100 km/h за ~2.3 s). Заявка към задната
     *  ос — сцеплението ѝ решава колко минава (виж tractionTarget). */
    enginePower: 13,

    /** Таван на спирачната система, m/s² (≈ 4.6 g). Реалното забавяне е
     *  min(това, brakeGripShare·хватката на сух асфалт) — на ниска скорост
     *  спира гумата, на висока — спирачката. */
    brakePower: 45,

    /** Дял от хватката на СУХ РАВЕН асфалт, до който педалът е калибриран.
     *  Всичко по-хлъзгаво (чакъл, керб, било) прелива в ABS → блокиране. */
    brakeGripShare: 0.9,

    /** Спирачният баланс следва товара на осите (brake-by-wire), изместен с
     *  този дял към задницата: тя стига лимита първа → леко завъртане при
     *  спиране в завой (trail-braking), без да блокира на права. Фиксиран
     *  баланс с 1-битов педал би блокирал една ос във всяка бавна зона. */
    brakeBalanceRearShift: 0.02,

    /** ABS/TC-лайт: заявката към ос се реже на този дял от хватката ѝ.
     *  Клавиатурата няма как да дозира — без това всяко спиране блокира. */
    tractionTarget: 0.96,

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

    /** Спиране с двигателя при вдигната газ, m/s² (пълно над engineBrakingSpeed). */
    engineBraking: 2.8,
    engineBrakingSpeed: 15,

    /** Странично сцепление при нулева скорост, m/s². Вдигано на стъпки
     *  (22→25→27): с клавиатура повече базова хватка държи линията по-лесно. */
    baseGrip: 27,

    /** Прираст на сцеплението от притискащата сила: grip += coef * v².
     *  Смъкнат 0.011→0.009 във v3: с реален лимит и кръг на триенето
     *  бързите завои вече не са „на релси" и не им трябва изкуствен запас. */
    downforceCoef: 0.009,

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

    /** Педални рампи, единици/сек: клавишът е 1 бит, кракът — не. Газта
     *  нараства за ~0.3 s и пада за ~0.13 s; спирачката — 0.17 s / 0.08 s. */
    throttleRateUp: 3.5,
    throttleRateDown: 8,
    brakeRateUp: 6,
    brakeRateDown: 12,

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

    /** Ъгъл на плъзгане (рад), при който оста достига ПИКА си. Вдигнати
     *  ×(1+falloff) спрямо v2 (0.10/0.18), за да остане линейният участък
     *  същият: кривата тръгва с наклон (1+w). Задната по-прогресивна. */
    gripPeakSlipFront: 0.13,
    gripPeakSlipRear: 0.26,

    /** Спад след пика: тегло на рационалната „пачейка" 2s/(1+s²) в кривата
     *  на гумата. Плъзгащата хватка клони към (1−w) от пиковата: предницата
     *  прощава повече (понятен understeer), задницата наказва прекаленото. */
    tyreFalloffFront: 0.3,
    tyreFalloffRear: 0.45,

    /** Мащаб на инерцията на въртене (Iz = m·a·b·factor). По-малко = по-остра
     *  ротация. Леко вдигнат (1.25→1.5) за по-плавно, по-предвидимо завъртане —
     *  подходящо за браузърна игра. */
    yawInertiaFactor: 1.35,

    /** Затихване на въртенето, 1/s. Смъкнато 10% (4.2→3.8) във v3: спадът
     *  след пика вече прави плъзгането реално и не бива да е двойно гасено. */
    yawDamping: 3.8,

    /** Пиково аеро-затихване на въртенето (при максимална скорост), 1/s. Расте
     *  кубично със скоростта, затова е почти нула в бавните/средните завои и
     *  силно на права/висока скорост — лека корекция там не изхвърля задницата.
     *  Вдигнато (8→11) за повече стабилност на скорост. */
    yawDampingAero: 11.0,

    /** Таван на ъгловата скорост, rad/s. */
    maxYawRate: 1.7,

    /** Counter-steer асист: дял от ъгъла на плъзгане на тялото, който воланът
     *  сам връща по посока на движението (мек — колата остава за хващане, не
     *  се хваща сама). Мъртва зона в радиани; гасне от fadeStart до fadeEnd
     *  m/s, където аерото вече стабилизира. */
    counterSteerGain: 0.25,
    counterSteerDeadband: 0.03,
    counterSteerFadeStart: 60,
    counterSteerFadeEnd: 85,

    /** Кербът: по-малко хватка (боядисан бетон) + друсане от назъбването. */
    kerbGripFactor: 0.92,
    kerbYawKick: 0.35,
    kerbLateralKick: 0.5,
    kerbKickFullSpeed: 40,

    /** Праг на скоростта (m/s), под който караме кинематично (без ротация). */
    lowSpeedThreshold: 2.0,

    /** Коефициент на сцепление извън трасето. */
    offTrackGripFactor: 0.38,

    /** Допълнително забавяне извън трасето, m/s². */
    offTrackDrag: 9.0,
};

/** Земно ускорение, m/s². */
const GRAVITY = 9.81;

/** Фазово отместване на страничния керб-удар спрямо въртящия (1.3 rad като
 *  дял от периода) — двата не съвпадат, за да е друсането „на две колела". */
const KERB_LATERAL_PHASE = 0.207;

/**
 * @typedef {object} CarOut Телеметрия на стъпката — САМО изход: физиката
 *   никога не я чете, затова не влиза в снапшота на записа. Презаписва се
 *   на всяка стъпка (един обект, без алокации).
 * @property {number} steerAngle Ъгъл на предните колела, рад (вкл. асиста)
 * @property {number} slipAngF   Ъгъл на плъзгане на предната ос, рад
 * @property {number} slipAngR   Ъгъл на плъзгане на задната ос, рад
 * @property {number} rhoF       Комбинирано натоварване на предната ос:
 *                               √(u² + (|slip|/peak)²); ≥ 1 = отвъд пика
 * @property {number} rhoR       Същото за задната
 * @property {number} lockF      0..1 колко ABS-ът реже предницата (заявка над
 *                               хватката: чакъл, керб, било); 0 на сух асфалт
 * @property {number} lockR      0..1 същото за задницата при спиране
 * @property {number} spin       0..1 буксуване на задницата (заявка от газта
 *                               над хватката: чакъл/трева, изход с плъзгане)
 * @property {number} loadF      Дял от товара на предната ос (0..1)
 * @property {number} loadR      Дял на задната (= 1 − loadF)
 * @property {number} loadFactor Множител на нормалната сила от релефа:
 *                               < 1 било (олеква), > 1 компресия
 * @property {number} dfG        Притискаща сила като ускорение, m/s²
 * @property {number} ax         Надлъжно ускорение на тялото, m/s²
 * @property {number} ay         Странично ускорение от гумите (ayF + ayR),
 *                               m/s², по оста на vLateral
 * @property {boolean} absActive ABS/TC-лайт реже заявка в тази стъпка
 * @property {number} kerbSide   Страната на керба под колата (+1 по
 *                               нормалата, −1 срещу, 0 = не е на керб);
 *                               попълва sim.js
 * @property {{impulse: number, nx: number, nz: number, tick: number}|null} wallHit
 *                               Удар в стена (sim.js): нормална скорост,
 *                               нормала КЪМ трасето, тик на удара; държи се
 *                               няколко тика, за да го види и бавен кадър
 */

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
 * @property {number} throttlePedal Реалното положение на газта 0..1 (рампа)
 * @property {number} brakePedal   Реалното положение на спирачката 0..1
 * @property {number} slip         0..1, колко плъзга задницата — за ефекти
 * @property {CarOut} out          Телеметрия на последната стъпка
 */

/**
 * @typedef {object} CarInput
 * @property {number} throttle  [0, 1]
 * @property {number} brake     [0, 1]
 * @property {number} steer     [-1, 1] желана посока
 */

/**
 * @typedef {object} StepOptions Повърхностни/релефни условия на стъпката
 *   (sim.js). Всяко поле по подразбиране е неутрално (×1, +0, false).
 * @property {boolean} [onKerb]     Колата е върху керб (по-малко хватка + друсане)
 * @property {number} [kerbPhase]   Фаза на назъбването 0..1 (разстояние/0.9 m)
 * @property {number} [loadFactor]  Множител на нормалната сила от релефа
 * @property {number} [bankAccel]   Странично ускорение от наклона на платното
 *                                  по оста на vLateral, m/s²
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
        throttlePedal: 0,
        brakePedal: 0,
        slip: 0,
        out: createCarOut(),
    };
}

/**
 * @returns {CarOut}
 */
export function createCarOut() {
    return {
        steerAngle: 0,
        slipAngF: 0,
        slipAngR: 0,
        rhoF: 0,
        rhoR: 0,
        lockF: 0,
        lockR: 0,
        spin: 0,
        loadF: CAR.staticFrontLoad,
        loadR: 1 - CAR.staticFrontLoad,
        loadFactor: 1,
        dfG: 0,
        ax: 0,
        ay: 0,
        absActive: false,
        kerbSide: 0,
        wallHit: null,
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
 * @param {number} gripBoost Множител на сцеплението НА трасето — банкираният
 *        завой носи повече хватка (нормалната сила помага). 1 = равно платно.
 * @param {StepOptions|null} opts Керб/релеф/банкинг; null = неутрално.
 */
export function step(state, input, dt, onTrack, gradient = 0, offRoad = null, gripBoost = 1, opts = null) {
    const out = state.out;
    const onKerb = opts !== null && opts.onKerb === true;
    const loadFactor = opts !== null && opts.loadFactor !== undefined ? opts.loadFactor : 1;
    const bankAccel = opts !== null && opts.bankAccel !== undefined ? opts.bankAccel : 0;

    const gripFactor = onTrack
        ? onKerb ? CAR.kerbGripFactor : 1
        : offRoad?.gripFactor ?? CAR.offTrackGripFactor;

    // ── Педали ───────────────────────────────────────────────────────────
    // Клавишът е 1 бит; кракът — рампа. Рампата е линейна (clamp, не
    // експонента), затова педалът стига ТОЧНО 0 и 1 за краен брой стъпки.
    state.throttlePedal += clamp(
        input.throttle - state.throttlePedal,
        -CAR.throttleRateDown * dt,
        CAR.throttleRateUp * dt
    );
    state.brakePedal += clamp(
        input.brake - state.brakePedal,
        -CAR.brakeRateDown * dt,
        CAR.brakeRateUp * dt
    );
    const throttle = state.throttlePedal;
    const brake = state.brakePedal;

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

    // ── Хватка ───────────────────────────────────────────────────────────
    const vx = state.vForward;
    const absV = Math.abs(vx);

    const dfG = CAR.downforceCoef * absV * absV;
    // Референция: сух равен асфалт — за нея е калибриран спирачният педал.
    const gripRef = CAR.baseGrip + dfG;
    // Реалната повърхност под колата + релефът (било/компресия).
    const gripSurface = gripRef * gripFactor * loadFactor;
    const brakeCap = Math.min(CAR.brakePower, CAR.brakeGripShare * gripRef);

    // ── Надлъжни заявки (преди осите) ───────────────────────────────────
    const driveDemand = throttle > 0 && vx < CAR.maxSpeed ? CAR.enginePower * throttle : 0;
    const braking = brake > 0 && vx > 0.5;
    const brakeDemand = braking ? brakeCap * brake : 0;

    let passive = 0;
    passive -= CAR.drag * vx * absV;
    passive -= CAR.rollingResistance * vx * (onTrack ? 1 : 3);

    if (!onTrack) {
        passive -= Math.sign(vx) * (offRoad?.drag ?? CAR.offTrackDrag);
    }

    // Съставяща на тежестта по склона. `gradient` е тангенсът на наклона, а на
    // нас ни трябва синусът — при 18% (Ео Руж) разликата е 1.6%, но е евтина.
    if (gradient !== 0) {
        passive -= GRAVITY * (gradient / Math.sqrt(1 + gradient * gradient));
    }

    // Спиране с двигателя: вдигната газ = задните колела дърпат назад.
    // Извън трасето наполовина — там колелата и без това буксуват.
    if (throttle < 0.05 && vx > 1) {
        passive -= CAR.engineBraking * Math.min(1, vx / CAR.engineBrakingSpeed) * (onTrack ? 1 : 0.5);
    }

    if (brake > 0 && !braking && vx > -CAR.maxReverseSpeed) {
        // Под прага спирачката става заден ход.
        passive -= CAR.reversePower * brake;
    }

    // ── Прехвърляне на тегло ─────────────────────────────────────────────
    // Надлъжното ускорение движи товара (спирачка → отпред, газ → отзад).
    // Оценка ПРЕДИ таваните на осите (единствената разлика от реалното е
    // ABS/TC-рязането на хлъзгаво); знаменателят включва притискащата сила —
    // на 300 km/h тя е 6× теглото и прехвърлянето е малка част от товара.
    const axEstimate = driveDemand - brakeDemand + passive;
    const shift = clamp(
        (CAR.cgHeight / CAR.wheelbase) * (axEstimate / (GRAVITY + dfG)),
        -CAR.maxLoadTransfer,
        CAR.maxLoadTransfer
    );
    const loadF = clamp(CAR.staticFrontLoad - shift, 0.1, 0.9);
    const loadR = 1 - loadF;

    // ── Осите по надлъжната ос: кръг на триенето + ABS/TC-лайт ──────────
    // Спирачният баланс следва товара (brake-by-wire), изместен леко назад.
    const biasF = clamp(loadF - CAR.brakeBalanceRearShift, 0.3, 0.9);
    const capF = gripSurface * loadF;
    const capR = gripSurface * loadR;

    const demandF = brakeDemand * biasF;
    const demandR = driveDemand - brakeDemand * (1 - biasF);
    const useFRaw = demandF / capF;
    const useRRaw = Math.abs(demandR) / capR;
    const useF = Math.min(useFRaw, CAR.tractionTarget);
    const useR = Math.min(useRRaw, CAR.tractionTarget);

    const forceF = -useF * capF;
    const forceR = demandR < 0 ? -useR * capR : useR * capR;

    // Изходи: колко ABS/TC-ът реже (заявка над хватката: чакъл, керб, било,
    // трева). 0 на сух равен асфалт по права — брейкът е под лимита на гумата.
    const overflowF = clamp((useFRaw - CAR.tractionTarget) * 2, 0, 1);
    const overflowR = clamp((useRRaw - CAR.tractionTarget) * 2, 0, 1);
    out.lockF = overflowF;
    out.lockR = demandR < 0 ? overflowR : 0;
    out.spin = demandR > 0 ? overflowR : 0;
    out.absActive = overflowF > 0 || overflowR > 0;

    const accel = forceF + forceR + passive;
    const axBody = accel;

    state.vForward += accel * dt;

    // Спирачката не бива да тласка колата назад в рамките на една стъпка.
    if (brake > 0 && throttle === 0 && Math.abs(state.vForward) < 0.3) {
        state.vForward = 0;
    }

    state.vForward = clamp(state.vForward, -CAR.maxReverseSpeed, CAR.maxSpeed);

    // ── Странична динамика: двуосев модел ────────────────────────────────
    // Отделни странични сили на предната и задната ос, всяка с таван по своята
    // хватка. Когато задната се насити (олекнала на входа при спиране или
    // „изядена" от газта на изход), колата ротира от задницата — контролируем
    // oversteer като в F1.

    // Кормилен ъгъл на предните колела — с падане по скоростта, както преди.
    let steerAngle = (CAR.maxSteerAngle * state.steer) / (1 + absV * CAR.steerSpeedFalloff);

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

        out.slipAngF = 0;
        out.slipAngR = 0;
        out.rhoF = useF;
        out.rhoR = useR;
        out.ay = 0;
    } else {
        // Counter-steer асист: воланът сам връща дял от ъгъла на плъзгане на
        // тялото (β ≈ vLateral/vx, рационално) по посоката на движението —
        // рамото на клавиатурния пилот, който няма аналогов волан. Гасне на
        // висока скорост, където аеро-затихването върши същото.
        const beta = state.vLateral / Math.max(absV, 3);
        const assistBeta = beta - clamp(beta, -CAR.counterSteerDeadband, CAR.counterSteerDeadband);
        const assistFade = clamp(
            (CAR.counterSteerFadeEnd - absV) / (CAR.counterSteerFadeEnd - CAR.counterSteerFadeStart),
            0,
            1
        );
        steerAngle += CAR.counterSteerGain * assistFade * assistBeta;

        // Геометрия: CG по-близо до задницата (staticFrontLoad = b/L).
        const a = CAR.wheelbase * (1 - CAR.staticFrontLoad); // CG → предна ос
        const b = CAR.wheelbase * CAR.staticFrontLoad; //        CG → задна ос

        // Кръг на триенето: каквото спирачката/газта вземат от оста, липсва
        // на завиването (√(1−u²) — Math.sqrt е коректно закръглен навсякъде).
        // При ABS на лимита (u = 0.96) остават 28% странична хватка → пълна
        // спирачка в завой = understeer; trail-braking е дозиране с педала.
        // gripBoost (банкингът) действа само на СТРАНИЧНАТА хватка.
        const bankGrip = onTrack ? gripBoost : 1;
        const gripF = capF * bankGrip * Math.sqrt(1 - useF * useF);
        const gripR = capR * bankGrip * Math.sqrt(1 - useR * useR);

        // Ъгли на плъзгане на всяка ос (завъртени са само предните колела).
        const slipF = steerAngle - (state.vLateral + a * state.yawRate) / vx;
        const slipR = -(state.vLateral - b * state.yawRate) / vx;

        // Странични ускорения по кривата на гумата: линейно до пика, после
        // спад — прекаленото завиване ГУБИ хватка, лимитът се усеща.
        const sF = slipF / CAR.gripPeakSlipFront;
        const sR = slipR / CAR.gripPeakSlipRear;
        const ayF = gripF * tyreCurve(sF, CAR.tyreFalloffFront);
        const ayR = gripR * tyreCurve(sR, CAR.tyreFalloffRear);

        // Въртене: моментът от двете оси минус затихване. Iz = m·a·b·factor,
        // затова масата се съкращава и работим в ускорения.
        const izz = a * b * CAR.yawInertiaFactor;
        // Затихването расте кубично със скоростта (аеро-стабилност) → правата е
        // спокойна, а бавните/средните завои остават живи.
        const speedRatio = absV / CAR.maxSpeed;
        const damping = CAR.yawDamping + CAR.yawDampingAero * speedRatio * speedRatio * speedRatio;
        let yawAccel = (a * ayF - b * ayR) / izz - damping * state.yawRate;

        // Странична скорост: сумата от осите + наклонът на платното минус
        // центростремителната връзка.
        let latAccel = ayF + ayR + bankAccel - state.yawRate * vx;

        // Кербът друса: назъбването (период 0.9 m, споделен с меша, звука и
        // камерата) бута колата ритмично по фазата на изминатото разстояние —
        // детерминирано от позицията, не от времето. Периодична рационална
        // вълна вместо sin, за да е бит-идентична между двигателите.
        if (onKerb) {
            const phase = opts.kerbPhase;
            const kick = Math.min(1, absV / CAR.kerbKickFullSpeed);
            yawAccel += periodicWave(phase) * CAR.kerbYawKick * kick;
            latAccel += periodicWave(phase + KERB_LATERAL_PHASE) * CAR.kerbLateralKick * kick;
        }

        state.yawRate = clamp(
            state.yawRate + yawAccel * dt,
            -CAR.maxYawRate,
            CAR.maxYawRate
        );

        state.vLateral += latAccel * dt;
        // Не оставяме плъзгането да избяга извън разумното.
        state.vLateral = clamp(state.vLateral, -absV - 6, absV + 6);

        state.heading += state.yawRate * dt;

        // За визуализация: колко се плъзга задницата (0..1).
        state.slip = clamp(Math.abs(state.vLateral) / Math.max(absV, 1), 0, 1);

        out.slipAngF = slipF;
        out.slipAngR = slipR;
        out.rhoF = Math.sqrt(useF * useF + sF * sF);
        out.rhoR = Math.sqrt(useR * useR + sR * sR);
        out.ay = ayF + ayR;
    }

    out.steerAngle = steerAngle;
    out.loadF = loadF;
    out.loadR = loadR;
    out.loadFactor = loadFactor;
    out.dfG = dfG;
    out.ax = axBody;

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
 * Кривата на гумата, нормирана: s = плъзгане/пик. До s = 1 расте (с наклон
 * 1+w в началото), на s = 1 е точно 1, после пада към (1−w). Само +−×/ —
 * бит-идентична навсякъде, за разлика от sin·atan на Pacejka.
 *
 * @param {number} s Нормирано плъзгане (със знак)
 * @param {number} w Тегло на спада след пика (0 = плато както във v2)
 * @returns {number} Нормирана сила със знака на s, |f| ≤ 1
 */
function tyreCurve(s, w) {
    const abs = s < 0 ? -s : s;
    const sat = abs < 1 ? abs : 1;
    const pac = (2 * abs) / (1 + abs * abs);
    const f = (1 - w) * sat + w * pac;

    return s < 0 ? -f : f;
}

/**
 * Периодична вълна с период 1, стойности в [−1, 1], без трансцендентни
 * функции: приближението на Баскара за sin(π·t) върху всяка половина.
 *
 * @param {number} phase Произволна фаза (периодът е 1)
 * @returns {number}
 */
function periodicWave(phase) {
    const p = phase - Math.floor(phase);
    const t = p < 0.5 ? p * 2 : p * 2 - 1;
    const half = (16 * t * (1 - t)) / (5 - 4 * t * (1 - t));

    return p < 0.5 ? half : -half;
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
