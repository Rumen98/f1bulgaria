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
