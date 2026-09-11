/**
 * Форматиране на времена. Отделено от Game.js нарочно: този модул няма
 * зависимост от three.js, така че страницата може да го импортва статично,
 * без да влачи 3D двигателя в основния бъндъл (и без да гърми при SSR).
 */

/**
 * Форматира време в м:сс.ххх — както изглежда в тайминга на Формула 1.
 *
 * @param {number|null|undefined} seconds
 * @returns {string}
 */
export function formatLapTime(seconds) {
    if (seconds === null || seconds === undefined) {
        return '--:--.---';
    }

    const minutes = Math.floor(seconds / 60);
    const rest = seconds - minutes * 60;

    return `${minutes}:${rest.toFixed(3).padStart(6, '0')}`;
}

/**
 * Разлика спрямо еталон, със знак: „-0.284" / „+1.902".
 *
 * @param {number|null|undefined} seconds
 * @param {number|null|undefined} reference
 * @returns {string|null}
 */
export function formatDelta(seconds, reference) {
    if (seconds === null || seconds === undefined || reference === null || reference === undefined) {
        return null;
    }

    const delta = seconds - reference;

    return `${delta >= 0 ? '+' : '−'}${Math.abs(delta).toFixed(3)}`;
}

/**
 * Интервал/делта спрямо нулата, както в ТВ кулата: „+0.842". Три знака —
 * тайминга на Формула 1 показва хилядни навсякъде, не стотни.
 *
 * @param {number|null|undefined} seconds
 * @returns {string|null}
 */
export function formatGap(seconds) {
    return formatDelta(seconds, 0);
}

/**
 * Секунди с три знака (сектор/сплит), „—" при липса.
 *
 * @param {number|null|undefined} seconds
 * @returns {string}
 */
export function formatSeconds(seconds) {
    return seconds === null || seconds === undefined ? '—' : seconds.toFixed(3);
}

/**
 * Кумулативни сплитове (време от старта до края на сектор i) → продължителност
 * на всеки сектор. Секторът е известен само когато е известен и предишният —
 * иначе разликата няма смисъл.
 *
 * @param {Array<number|null|undefined>} splits
 * @param {number} [count]
 * @returns {Array<number|null>}
 */
export function splitDurations(splits, count = 3) {
    const durations = new Array(count).fill(null);
    let previous = 0;

    for (let i = 0; i < count; i++) {
        const split = splits[i];
        if (typeof split !== 'number' || previous === null) {
            previous = null;
            continue;
        }
        durations[i] = Math.max(0, split - previous);
        previous = split;
    }

    return durations;
}
