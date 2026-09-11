/**
 * Дребните сметки зад ръчно писаните SVG графики в „Данни".
 *
 * В проекта няма библиотека за графики и не се добавя такава: всичко видимо
 * досега е ръчен SVG (пистите, купата, коричките на новините), а една линия с
 * оси не оправдава 60 KB зависимост. Тук стои общото, за да не се преписва във
 * всеки компонент.
 *
 * Всички функции са чисти и работят при сървърен рендер — никакъв достъп до
 * window, document или Math.random.
 */

/** Полето за рисуване вътре в един viewBox. */
export function plot(width, height, padding = {}) {
    const p = { top: 12, right: 14, bottom: 26, left: 38, ...padding };

    return {
        ...p,
        width,
        height,
        innerWidth: Math.max(1, width - p.left - p.right),
        innerHeight: Math.max(1, height - p.top - p.bottom),
    };
}

/**
 * Линейна скала: стойност от [min, max] към пиксели от [from, to].
 * Нулев обхват не бива да дава NaN — връща средата.
 */
export function scale(min, max, from, to) {
    const span = max - min;

    if (!Number.isFinite(span) || span === 0) {
        return () => (from + to) / 2;
    }

    return (value) => from + ((value - min) / span) * (to - from);
}

/** Точките за <polyline>. Пропуска всичко, което не е крайно число. */
export function points(pairs, x, y) {
    return pairs
        .filter(([a, b]) => Number.isFinite(a) && Number.isFinite(b))
        .map(([a, b]) => `${round(x(a))},${round(y(b))}`)
        .join(' ');
}

/**
 * Кръгли числа за оста: около `count` деления в обхвата, без дробни стойности,
 * които никой не чете. На тесен екран се иска по-малко, за да не се слепят.
 */
export function ticks(min, max, count = 5) {
    if (!Number.isFinite(min) || !Number.isFinite(max) || min === max) {
        return [min];
    }

    const raw = (max - min) / count;
    const magnitude = 10 ** Math.floor(Math.log10(raw));
    const step = [1, 2, 2.5, 5, 10].map((m) => m * magnitude).find((s) => s >= raw) ?? magnitude * 10;

    const out = [];

    for (let value = Math.ceil(min / step) * step; value <= max + step / 1000; value += step) {
        out.push(Number(value.toFixed(6)));
    }

    return out;
}

/**
 * Отместването на базовата линия, при което етикетът стои по средата на своята
 * решетъчна линия. Зависи от шрифта, а той вече е различен на тясно — затова
 * не е константа „+4“ по компонентите.
 */
export function baselineShift(fontSize) {
    return round(fontSize * 0.36);
}

/** Два знака стигат за пиксел — по-дълги числа само надуват HTML-а. */
export function round(value) {
    return Math.round(value * 100) / 100;
}

/** Секунди във вид „1:21.500" — както се пише времето на обиколка. */
export function lapTime(seconds) {
    if (!Number.isFinite(seconds)) {
        return '—';
    }

    const minutes = Math.floor(seconds / 60);
    const rest = (seconds - minutes * 60).toFixed(3).padStart(6, '0');

    return `${minutes}:${rest}`;
}

/** Десетичните числа на български се пишат със запетая. */
export function bg(value, digits = 1) {
    if (!Number.isFinite(value)) {
        return '—';
    }

    return value.toFixed(digits).replace('.', ',');
}
