/**
 * Геймпад: аналогов волан, газ/спирачка от тригерите, смяна на предавка от
 * бъмперите и вибрация (кербове, блокирали гуми, удари) — огледало на
 * navigator.vibrate за телефона.
 *
 * Детерминизъм: воланът е −1..1 float, точно както от тъч контролите; трейсът
 * го записва като Int8 (sim.js), така че симулацията и сървърната
 * валидация не се променят. Газ/спирачка са 1-битови в трейса → тригерите
 * се прагуват на 0.5 (педалните рампи във физиката ги правят „аналогови").
 *
 * Няма module-level достъп до navigator — модулът се импортва и в node
 * (selftest, build-ghosts не го ползват, но пътят трябва да е чист).
 */

/** Мъртва зона на стика — под нея дрейфът на стария контролер не върти. */
const DEADZONE = 0.08;

/** Тригерът се брои за натиснат над този праг (1-битов трейс). */
const TRIGGER_THRESHOLD = 0.5;

/** Минимален интервал между два кербови импулса (ms) — да не бръмчи. */
const KERB_PULSE_INTERVAL = 90;

/**
 * Профили на вибрацията: dual-rumble (силен мотор = нискочестотен, слаб =
 * високочестотен). Кербът е тик, блокирането — тласък, стената — удар.
 */
const HAPTIC_EFFECTS = Object.freeze({
    kerb: { duration: 8, strongMagnitude: 0.0, weakMagnitude: 0.35 },
    lock: { duration: 40, strongMagnitude: 0.6, weakMagnitude: 0.2 },
    impact: { duration: 120, strongMagnitude: 1.0, weakMagnitude: 0.6 },
    launch: { duration: 40, strongMagnitude: 0.5, weakMagnitude: 0.5 },
});

/**
 * Стандартно mapping (W3C): ос 0 = ляв стик X, бутон 6/7 = LT/RT (аналогови
 * .value), 4/5 = LB/RB, 0 = A, 1 = B, 2 = X.
 */
const AXIS_STEER = 0;
const BUTTON_BRAKE_TRIGGER = 6;
const BUTTON_THROTTLE_TRIGGER = 7;
const BUTTON_SHIFT_DOWN = 4;
const BUTTON_SHIFT_UP = 5;
const BUTTON_A = 0;
const BUTTON_B = 1;
const BUTTON_X = 2;

/** Състояние между кадрите: фронтове на бъмперите и последният импулс. */
const pad = {
    connected: false,
    shiftUpHeld: false,
    shiftDownHeld: false,
    pendingShift: 0,
    lastKerbPulse: 0,
};

/**
 * Първият свързан геймпад (null без такъв или без API).
 *
 * @returns {Gamepad|null}
 */
function activeGamepad() {
    if (typeof navigator === 'undefined' || typeof navigator.getGamepads !== 'function') {
        return null;
    }
    const pads = navigator.getGamepads();
    for (let i = 0; i < pads.length; i++) {
        if (pads[i] !== null && pads[i] !== undefined && pads[i].connected) {
            return pads[i];
        }
    }
    return null;
}

/**
 * Кубична крива с мъртва зона: фин център, пълен ход в края. Чисто x³ прави
 * половин стик 12 % волан — твърде вяло за хеърпин; смес 0.35·x + 0.65·x³.
 *
 * @param {number} raw −1..1
 * @returns {number}
 */
function steerCurve(raw) {
    const abs = Math.abs(raw);
    if (abs < DEADZONE) {
        return 0;
    }
    const x = Math.min(1, (abs - DEADZONE) / (1 - DEADZONE));
    return Math.sign(raw) * x * (0.35 + 0.65 * x * x);
}

/**
 * @param {Gamepad} gamepad
 * @param {number} index
 * @returns {number} 0..1
 */
function buttonValue(gamepad, index) {
    const button = gamepad.buttons[index];
    if (!button) {
        return 0;
    }
    return typeof button.value === 'number' ? button.value : button.pressed ? 1 : 0;
}

/**
 * Чете геймпада в `input` (в конвенцията на симулацията: + волан = физическо
 * надясно, което chase камерата показва вляво — Game обръща клавиатурата по
 * същия начин). Пише САМО когато има реален вход, за да не изтрива
 * клавиатурата/тъча при неутрален контролер в USB-то.
 *
 * @param {{throttle: number, brake: number, steer: number}} input
 * @returns {boolean} Дали контролерът даде вход този кадър
 */
export function readGamepad(input) {
    const gamepad = activeGamepad();
    pad.connected = gamepad !== null;
    if (gamepad === null) {
        pad.shiftUpHeld = false;
        pad.shiftDownHeld = false;
        return false;
    }

    const steer = steerCurve(gamepad.axes[AXIS_STEER] ?? 0);
    const throttle =
        Math.max(buttonValue(gamepad, BUTTON_THROTTLE_TRIGGER), buttonValue(gamepad, BUTTON_A)) >=
        TRIGGER_THRESHOLD
            ? 1
            : 0;
    const brake =
        Math.max(
            buttonValue(gamepad, BUTTON_BRAKE_TRIGGER),
            buttonValue(gamepad, BUTTON_B),
            buttonValue(gamepad, BUTTON_X)
        ) >= TRIGGER_THRESHOLD
            ? 1
            : 0;

    // Бъмперите: само фронтът (натискане), както event.repeat спира W/S.
    const upHeld = buttonValue(gamepad, BUTTON_SHIFT_UP) >= TRIGGER_THRESHOLD;
    const downHeld = buttonValue(gamepad, BUTTON_SHIFT_DOWN) >= TRIGGER_THRESHOLD;
    if (upHeld && !pad.shiftUpHeld) {
        pad.pendingShift = 1;
    } else if (downHeld && !pad.shiftDownHeld) {
        pad.pendingShift = -1;
    }
    pad.shiftUpHeld = upHeld;
    pad.shiftDownHeld = downHeld;

    const used = steer !== 0 || throttle > 0 || brake > 0;
    if (!used) {
        return false;
    }

    input.steer = -steer;
    input.throttle = throttle;
    input.brake = brake;

    return true;
}

/**
 * Изчаква се от Game при ръчна трансмисия: +1 = нагоре, −1 = надолу, 0 = нищо.
 * Еднократно — изчистването е тук, за да не се смени два пъти на един фронт.
 *
 * @returns {-1|0|1}
 */
export function consumeShift() {
    const shift = pad.pendingShift;
    pad.pendingShift = 0;
    return shift;
}

/**
 * @returns {boolean} Дали има свързан контролер (от последното readGamepad)
 */
export function gamepadConnected() {
    return pad.connected;
}

/**
 * Вибрация по вид събитие. Тихо без актуатор (Safari, стари контролери):
 * playEffect връща promise, който може да reject-не — гълта се.
 *
 * @param {'kerb'|'lock'|'impact'|'launch'} kind
 * @param {number} [scale] Множител на силата (удар по импулс), 0..1
 * @returns {boolean} Дали импулсът е изпратен
 */
export function hapticPulse(kind, scale = 1) {
    const effect = HAPTIC_EFFECTS[kind];
    if (!effect) {
        return false;
    }
    const gamepad = activeGamepad();
    const actuator = gamepad?.vibrationActuator;
    if (!actuator || typeof actuator.playEffect !== 'function') {
        return false;
    }

    if (kind === 'kerb') {
        const now = typeof performance !== 'undefined' ? performance.now() : Date.now();
        if (now - pad.lastKerbPulse < KERB_PULSE_INTERVAL) {
            return false;
        }
        pad.lastKerbPulse = now;
    }

    const strength = Math.max(0, Math.min(1, scale));
    const result = actuator.playEffect('dual-rumble', {
        startDelay: 0,
        duration: effect.duration,
        strongMagnitude: effect.strongMagnitude * strength,
        weakMagnitude: effect.weakMagnitude * strength,
    });
    if (result && typeof result.catch === 'function') {
        result.catch(() => {});
    }

    return true;
}
