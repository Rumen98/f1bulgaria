/**
 * Превръща метричните данни на пистата (public/game-tracks/*.json) в структура,
 * годна за физика и за генериране на 3D мрежа.
 *
 * Точките идват равномерно разпределени (spacing метра по хоризонтала),
 * затворен цикъл, като последната НЕ повтаря първата — всички индекси се
 * wrap-ват по модул.
 *
 * Тангентите и нормалите са ХОРИЗОНТАЛНИ (в XZ равнината). Наклонът живее
 * отделно, в `gradient`. Това е нарочно: физиката работи в две измерения с
 * посока и скорост, а височината влиза само през гравитацията. Опростява
 * симулацията, без да отнема нищо от усещането — колата и без това не се
 * отделя от асфалта.
 */

/** Праг на кривина (1/m), над който завоят получава керб. */
const KERB_CURVATURE = 0.008;

/** Колко пъти да се изглади кривината. Второто производно на GPS данни е шумно. */
const CURVATURE_SMOOTHING_PASSES = 4;

/**
 * @typedef {object} Track
 * @property {string} slug
 * @property {string} name
 * @property {number} length      Дължина на обиколката в метри
 * @property {number} width       Ширина на трасето в метри
 * @property {number} spacing     Хоризонтално разстояние между съседни точки
 * @property {Float32Array} xs
 * @property {Float32Array} ys    Височина в метри
 * @property {Float32Array} zs
 * @property {Float32Array} tx    Хоризонтална тангента X (единичен вектор)
 * @property {Float32Array} tz
 * @property {Float32Array} nx    Хоризонтална нормала X (наляво спрямо посоката)
 * @property {Float32Array} nz
 * @property {Float32Array} gradient   dy/ds по посоката на движение
 * @property {Float32Array} curvature  Знакова кривина, 1/m (+ = ляв завой)
 * @property {number} count
 * @property {number} elevationRange
 */

/**
 * @param {object} data Съдържанието на {slug}.json
 * @returns {Track}
 */
export function prepareTrack(data) {
    const count = data.points.length;
    const spacing = data.spacing;

    const xs = new Float32Array(count);
    const ys = new Float32Array(count);
    const zs = new Float32Array(count);

    for (let i = 0; i < count; i++) {
        const p = data.points[i];
        xs[i] = p[0];
        // Търпим и стария двумерен формат [x, z] — тогава трасето е плоско.
        ys[i] = p.length > 2 ? p[1] : 0;
        zs[i] = p.length > 2 ? p[2] : p[1];
    }

    const tx = new Float32Array(count);
    const tz = new Float32Array(count);
    const nx = new Float32Array(count);
    const nz = new Float32Array(count);
    const gradient = new Float32Array(count);
    let curvature = new Float32Array(count);

    for (let i = 0; i < count; i++) {
        const prev = (i - 1 + count) % count;
        const next = (i + 1) % count;

        // Централна разлика — точките са равноотдалечени, така че знаменателят
        // е константа и се съкращава при нормализирането.
        let dx = xs[next] - xs[prev];
        let dz = zs[next] - zs[prev];
        const len = Math.hypot(dx, dz) || 1;
        dx /= len;
        dz /= len;

        tx[i] = dx;
        tz[i] = dz;

        // Нормала наляво спрямо посоката на движение (XZ равнина, Y нагоре).
        nx[i] = -dz;
        nz[i] = dx;

        gradient[i] = (ys[next] - ys[prev]) / (2 * spacing);

        // Знакова кривина от първо и второ производно, в хоризонталната равнина.
        const d1x = (xs[next] - xs[prev]) / (2 * spacing);
        const d1z = (zs[next] - zs[prev]) / (2 * spacing);
        const d2x = (xs[next] - 2 * xs[i] + xs[prev]) / (spacing * spacing);
        const d2z = (zs[next] - 2 * zs[i] + zs[prev]) / (spacing * spacing);
        const denom = Math.pow(d1x * d1x + d1z * d1z, 1.5) || 1e-9;

        curvature[i] = (d1x * d2z - d1z * d2x) / denom;
    }

    for (let pass = 0; pass < CURVATURE_SMOOTHING_PASSES; pass++) {
        curvature = smoothCyclic(curvature);
    }

    let minY = Infinity;
    let maxY = -Infinity;
    for (let i = 0; i < count; i++) {
        if (ys[i] < minY) minY = ys[i];
        if (ys[i] > maxY) maxY = ys[i];
    }

    return {
        slug: data.slug,
        name: data.name,
        location: data.location,
        length: data.length,
        width: data.width,
        spacing,
        xs,
        ys,
        zs,
        tx,
        tz,
        nx,
        nz,
        gradient,
        curvature,
        count,
        elevationRange: maxY - minY,
        // Реални контури от OpenStreetMap (ODbL) — виж game:fetch-landmarks.
        landmarks: data.landmarks ?? null,
    };
}

/**
 * Плъзгащо средно по 3 съседни стойности, с wrap в двата края.
 *
 * @param {Float32Array} values
 * @returns {Float32Array}
 */
function smoothCyclic(values) {
    const n = values.length;
    const out = new Float32Array(n);

    for (let i = 0; i < n; i++) {
        out[i] = (values[(i - 1 + n) % n] + values[i] + values[(i + 1) % n]) / 3;
    }

    return out;
}

/**
 * Проектира световна позиция върху осевата линия на пистата.
 *
 * Търси локално около `hint` — колата се движи непрекъснато, така че между
 * два кадъра индексът се мести с шепа позиции. Без hint (или при рестарт)
 * се минава глобално.
 *
 * @param {Track} track
 * @param {number} x
 * @param {number} z
 * @param {number|null} hint Последният известен индекс
 * @returns {{index: number, lateral: number, distance: number, height: number, gradient: number}}
 *          lateral: отместване от осевата линия в метри (+ = наляво)
 *          distance: изминато разстояние по обиколката в метри
 *          height: височина на асфалта под тази позиция
 */
export function projectOnTrack(track, x, z, hint = null) {
    const { xs, zs, count } = track;

    let bestIndex = 0;
    let bestDistSq = Infinity;

    // Прозорецът трябва да покрива преместването за стъпка (~0.8 m при 92 m/s и
    // 1/120 s, т.е. под една точка), но да е ТЕСЕН: на стръмен хеърпин трасето
    // минава близо до себе си на различна височина. Широк прозорец (40) караше
    // проекцията да „прескача" на другата страна → колата взимаше грешната
    // височина и потъваше. 10 точки (40 m) е достатъчен резерв срещу лаг, без
    // да стига до отсрещния клон на завоя.
    const window = 10;
    const start = hint === null ? 0 : hint - window;
    const end = hint === null ? count : hint + window;

    for (let k = start; k < end; k++) {
        const i = ((k % count) + count) % count;
        const dx = x - xs[i];
        const dz = z - zs[i];
        const distSq = dx * dx + dz * dz;

        if (distSq < bestDistSq) {
            bestDistSq = distSq;
            bestIndex = i;
        }
    }

    // Отместването се мери спрямо нормалата в намерената точка.
    const dx = x - xs[bestIndex];
    const dz = z - zs[bestIndex];
    const lateral = dx * track.nx[bestIndex] + dz * track.nz[bestIndex];

    // Проекция върху тангентата — дава подпозиционна точност между две точки,
    // без която таймерът на сектор би скачал на стъпки от `spacing`.
    const along = dx * track.tx[bestIndex] + dz * track.tz[bestIndex];

    return {
        index: bestIndex,
        lateral,
        distance: bestIndex * track.spacing + along,
        height: heightAt(track, bestIndex, along),
        gradient: track.gradient[bestIndex],
    };
}

/**
 * Височина на асфалта на `along` метра след точка `index`.
 *
 * Интерполира между съседните точки — без това колата се качва на стъпала от
 * по 4 метра и на всеки праг подскача.
 *
 * @param {Track} track
 * @param {number} index
 * @param {number} along
 * @returns {number}
 */
export function heightAt(track, index, along) {
    const { ys, spacing, count } = track;

    // `along` може да е отрицателно, когато позицията е малко преди точката.
    const steps = along / spacing;
    const base = Math.floor(steps);
    const t = steps - base;

    const a = ys[(((index + base) % count) + count) % count];
    const b = ys[(((index + base + 1) % count) + count) % count];

    return a + (b - a) * t;
}

/**
 * Точките, в които трасето получава керб, като индексни интервали.
 *
 * Кербът е от ВЪТРЕШНАТА страна на завоя (там, където колата реже) —
 * знакът на кривината дава коя страна е това.
 *
 * @param {Track} track
 * @returns {Array<{from: number, to: number, side: number}>} side: +1 ляво, -1 дясно
 */
export function findKerbRanges(track) {
    const { curvature, count } = track;
    const ranges = [];

    let current = null;

    for (let i = 0; i < count; i++) {
        const k = curvature[i];
        const side = k > KERB_CURVATURE ? 1 : k < -KERB_CURVATURE ? -1 : 0;

        if (side === 0) {
            if (current) {
                ranges.push(current);
                current = null;
            }
            continue;
        }

        if (current && current.side === side) {
            current.to = i;
        } else {
            if (current) {
                ranges.push(current);
            }
            current = { from: i, to: i, side };
        }
    }

    if (current) {
        ranges.push(current);
    }

    // Ако завоят пресича индекс 0, началото и краят са един и същи керб.
    if (ranges.length > 1) {
        const first = ranges[0];
        const last = ranges[ranges.length - 1];

        if (first.from === 0 && last.to === count - 1 && first.side === last.side) {
            last.to = first.to + count;
            ranges.shift();
        }
    }

    // Много късите отрязъци са шум в кривината, не истински завой.
    return ranges.filter((r) => r.to - r.from >= 3);
}
