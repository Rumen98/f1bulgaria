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
 *
 * ОРИЕНТАЦИЯ: данните идват в географски координати (x = изток, z = север).
 * three.js е дясноориентирана с y нагоре, така че северът трябва да е -z —
 * иначе светът се рендерира ОГЛЕДАЛНО и Parabolica става ляв завой. Затова
 * тук z се обръща веднъж при зареждане (точки И ориентири); всичко надолу по
 * веригата (тангенти, нормали, кривина, мешове) се извежда от обърнатите
 * координати и е коректно от само себе си.
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
 * @property {Float32Array} nx    Хоризонтална нормала X (надясно спрямо посоката)
 * @property {Float32Array} nz
 * @property {Float32Array} gradient   dy/ds по посоката на движение
 * @property {Float32Array} curvature  Знакова кривина, 1/m (+ = десен завой,
 *                                     т.е. завой към страната на нормалата)
 * @property {Float32Array} halfWidths Полуширина на трасето за всяка точка —
 *                                     базовата, наслоена с widthProfile от
 *                                     конфига на пистата (фунията на Монца Т1,
 *                                     тясната среда на Зандвоорт)
 * @property {Float32Array} bankSlope  Напречен наклон tan(ъгъл) със знак:
 *                                     y на повърхността = y - offset·bankSlope
 *                                     (вътрешният ръб на банкиран завой е
 *                                     по-ниско). Ненулев само в banking
 *                                     диапазоните от конфига.
 * @property {Float32Array} raceOffset Състезателната линия: страничен офсет
 *                                     от осевата линия (по нормалата) за
 *                                     всяка точка — широк вход, апекс, широк
 *                                     изход. Ползват я ботовете и декорът.
 * @property {Float32Array} raceCurv   Кривина НА линията (не на осевата) —
 *                                     по нея ботовете мерят колко бързо се
 *                                     минава завоят.
 * @property {number} count
 * @property {number} elevationRange
 */

/**
 * @param {object} data Съдържанието на {slug}.json
 * @param {object} [style] Визуалната идентичност (circuits.js) — оттам идват
 *        widthProfile и banking. Детерминирани от конфига → бъдещият сървърен
 *        replay ги възпроизвежда 1:1.
 * @returns {Track}
 */
export function prepareTrack(data, style = null) {
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
        // Север (z в данните) → -z в three.js — виж бележката за ориентацията.
        zs[i] = -(p.length > 2 ? p[2] : p[1]);
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

        // Нормала надясно спрямо посоката на движение (XZ равнина, Y нагоре,
        // z вече е обърнато). Формулата фиксира ориентацията на (t, n) рамката
        // независимо от данните — затова навивката на мешовете не зависи от нея.
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

    const halfWidths = buildHalfWidths(count, spacing, data.width / 2, style?.widthProfile ?? []);
    const bankSlope = buildBankSlope(count, spacing, curvature, style?.banking ?? []);

    // Състезателната линия: широк вход → апекс → широк изход. Смята се ТУК
    // (не офлайн), за да е една и съща за браузъра, ботовете и Node скриптовете
    // без допълнителна pipeline стъпка. ~1M евтини операции — под 50 ms.
    const raceOffset = buildRacingLineOffsets(count, xs, zs, nx, nz, halfWidths, curvature);
    const raceCurv = buildRaceCurvature(count, xs, zs, nx, nz, raceOffset);

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
        halfWidths,
        bankSlope,
        raceOffset,
        raceCurv,
        count,
        elevationRange: maxY - minY,
        // Реални контури от OpenStreetMap (ODbL) — виж game:fetch-landmarks.
        landmarks: flipLandmarks(data.landmarks ?? null),
    };
}

/**
 * Обръща z на ориентирите — същата трансформация като на точките на трасето
 * (север → -z), за да останат на реалните си места спрямо него.
 *
 * @param {{grandstands?: Array, buildings?: Array, trees?: Array}|null} landmarks
 * @returns {object|null}
 */
function flipLandmarks(landmarks) {
    if (!landmarks) {
        return null;
    }

    const flipRing = (ring) => ring.map(([x, z]) => [x, -z]);

    return {
        grandstands: (landmarks.grandstands ?? []).map(flipRing),
        buildings: (landmarks.buildings ?? []).map(flipRing),
        trees: (landmarks.trees ?? []).map(([x, z, s]) => [x, -z, s]),
    };
}

/**
 * Полуширина за всяка точка: базовата, върху която widthProfile диапазоните
 * се наслагват с плавни ~60 m преходи (иначе трасето прави стъпало).
 *
 * @param {number} count
 * @param {number} spacing
 * @param {number} baseHalf
 * @param {Array<{from: number, to: number, width: number}>} profile Метри по обиколката
 * @returns {Float32Array}
 */
function buildHalfWidths(count, spacing, baseHalf, profile) {
    const halves = new Float32Array(count).fill(baseHalf);
    const ramp = Math.max(1, Math.round(60 / spacing));

    for (const zone of profile) {
        const from = Math.round(zone.from / spacing);
        const to = Math.round(zone.to / spacing);
        const target = zone.width / 2;

        for (let r = from; r <= to; r++) {
            const i = ((r % count) + count) % count;

            // Рампите са ВЪТРЕ в диапазона — рампа отвъд `to` преливаше
            // разширението на Монца във вече завиващия първи апекс на шикана
            // (клампата по радиуса режеше платното, а кербът оставаше на
            // старата ширина и увисваше). min() покрива и къси зони.
            const w = Math.min(smooth01((r - from) / ramp), smooth01((to - r) / ramp));

            halves[i] = halves[i] + (target - halves[i]) * w;
        }
    }

    return halves;
}

/**
 * Напречният наклон (банкинг) за всяка точка: tan(ъгъла), със знак от
 * кривината в диапазона — вътрешният ръб на завоя е ПО-НИСКО. Рампи ~45 m
 * в двата края, за да не се появява стена от асфалт.
 *
 * @param {number} count
 * @param {number} spacing
 * @param {Float32Array} curvature
 * @param {Array<{from: number, to: number, deg: number}>} banking Метри по обиколката
 * @returns {Float32Array}
 */
function buildBankSlope(count, spacing, curvature, banking) {
    const slope = new Float32Array(count);
    const ramp = Math.max(1, Math.round(45 / spacing));

    for (const zone of banking) {
        const from = Math.round(zone.from / spacing);
        const to = Math.round(zone.to / spacing);

        // Посоката на завоя определя кой ръб пада: усредняваме кривината в
        // сърцевината на диапазона, за да не зависим от локалния шум.
        let sum = 0;
        for (let r = from; r <= to; r++) {
            sum += curvature[((r % count) + count) % count];
        }
        const sign = sum >= 0 ? 1 : -1;
        const magnitude = Math.tan((zone.deg * Math.PI) / 180) * sign;

        for (let r = from; r <= to; r++) {
            const i = ((r % count) + count) % count;

            // Рампите са вътре в диапазона (виж buildHalfWidths).
            const w = Math.min(smooth01((r - from) / ramp), smooth01((to - r) / ramp));

            slope[i] = magnitude * w;
        }
    }

    return slope;
}

/**
 * Състезателната линия като страничен офсет за всяка точка: „еластична лента"
 * в коридора на трасето — всяка точка се тегли към хордата между съседите си
 * (локално нулева кривина), ограничена от ширината. Резултатът е класиката:
 * широк вход, апекс от вътрешната, широк изход.
 *
 * Двустепенно: грубо решение върху подизвадка (информацията по дългите прави
 * пътува по 1 точка на итерация — на пълна резолюция не би стигнала), после
 * фино доизглаждане на пълната. Чисто детерминирано.
 *
 * @param {number} count
 * @param {Float32Array} xs
 * @param {Float32Array} zs
 * @param {Float32Array} nx
 * @param {Float32Array} nz
 * @param {Float32Array} halfWidths
 * @param {Float32Array} curvature Кривина на осевата — за маржа в острите завои
 * @returns {Float32Array}
 */
function buildRacingLineOffsets(count, xs, zs, nx, nz, halfWidths, curvature) {
    // Половин болид + резерв; кербовете отвъд ръба са допустими и реално.
    // В острите завои маржът расте: на хеърпин апекс до самия ръб кара
    // pursuit контролера на ботовете през вътрешната трева.
    const MARGIN = 1.35;
    const marginAt = (i) => MARGIN + Math.min(1.1, Math.abs(curvature[i]) * 14);

    // Релаксация на офсети e[] върху дадени опорни точки/нормали/граници.
    const relax = (n, px, pz, pnx, pnz, bounds, e, iterations) => {
        for (let iter = 0; iter < iterations; iter++) {
            for (let i = 0; i < n; i++) {
                const prev = (i - 1 + n) % n;
                const next = (i + 1) % n;

                const qpx = px[prev] + e[prev] * pnx[prev];
                const qpz = pz[prev] + e[prev] * pnz[prev];
                const qnx = px[next] + e[next] * pnx[next];
                const qnz = pz[next] + e[next] * pnz[next];

                // Проекция на средата на хордата върху нормалата в i.
                const desired =
                    ((qpx + qnx) / 2 - px[i]) * pnx[i] + ((qpz + qnz) / 2 - pz[i]) * pnz[i];

                let v = e[i] + (desired - e[i]) * 0.5;
                const b = bounds[i];
                e[i] = v < -b ? -b : v > b ? b : v;
            }
        }
    };

    // ── Грубо ниво: ~700 опорни точки ────────────────────────────────────
    const stride = Math.max(1, Math.ceil(count / 700));
    const cn = Math.ceil(count / stride);
    const cx = new Float32Array(cn);
    const cz = new Float32Array(cn);
    const cnx = new Float32Array(cn);
    const cnz = new Float32Array(cn);
    const cb = new Float32Array(cn);
    const ce = new Float32Array(cn);

    for (let ci = 0; ci < cn; ci++) {
        const i = ci * stride;
        cx[ci] = xs[i];
        cz[ci] = zs[i];
        cnx[ci] = nx[i];
        cnz[ci] = nz[i];
        // Границата е МИНИМУМЪТ в прозореца — иначе линията прелива в стеснение.
        let bound = Infinity;
        for (let k = i; k < Math.min(i + stride, count); k++) {
            bound = Math.min(bound, halfWidths[k] - marginAt(k));
        }
        cb[ci] = Math.max(0.3, bound);
    }

    relax(cn, cx, cz, cnx, cnz, cb, ce, 900);

    // ── Пълна резолюция: интерполация + фино доизглаждане ────────────────
    const offsets = new Float32Array(count);
    for (let i = 0; i < count; i++) {
        const pos = i / stride;
        const base = Math.floor(pos) % cn;
        const t = pos - Math.floor(pos);
        offsets[i] = ce[base] + (ce[(base + 1) % cn] - ce[base]) * t;
    }

    const bounds = new Float32Array(count);
    for (let i = 0; i < count; i++) {
        bounds[i] = Math.max(0.3, halfWidths[i] - marginAt(i));
    }

    relax(count, xs, zs, nx, nz, bounds, offsets, 80);

    // Финално изглаждане срещу зъбци от интерполацията + повторен клامп.
    let smoothed = smoothCyclic(smoothCyclic(offsets));
    for (let i = 0; i < count; i++) {
        const b = bounds[i];
        smoothed[i] = smoothed[i] < -b ? -b : smoothed[i] > b ? b : smoothed[i];
    }

    return smoothed;
}

/**
 * Знакова кривина на състезателната линия (описана окръжност през три
 * съседни точки — устойчива на неравното разстояние по офсетнатия път).
 *
 * @param {number} count
 * @param {Float32Array} xs
 * @param {Float32Array} zs
 * @param {Float32Array} nx
 * @param {Float32Array} nz
 * @param {Float32Array} raceOffset
 * @returns {Float32Array}
 */
function buildRaceCurvature(count, xs, zs, nx, nz, raceOffset) {
    const qx = new Float32Array(count);
    const qz = new Float32Array(count);
    for (let i = 0; i < count; i++) {
        qx[i] = xs[i] + raceOffset[i] * nx[i];
        qz[i] = zs[i] + raceOffset[i] * nz[i];
    }

    let curv = new Float32Array(count);
    for (let i = 0; i < count; i++) {
        const p = (i - 1 + count) % count;
        const n = (i + 1) % count;

        const abx = qx[i] - qx[p];
        const abz = qz[i] - qz[p];
        const bcx = qx[n] - qx[i];
        const bcz = qz[n] - qz[i];
        const acx = qx[n] - qx[p];
        const acz = qz[n] - qz[p];

        const cross = abx * bcz - abz * bcx; // 2·площ със знак
        const denom =
            Math.hypot(abx, abz) * Math.hypot(bcx, bcz) * Math.hypot(acx, acz) || 1e-9;

        curv[i] = (2 * cross) / denom;
    }

    // Само 2 паса (не 4-те на осевата): повече изглаждане затъпява пика на
    // хеърпина и ботовете влизат в него с излишна скорост.
    for (let pass = 0; pass < 2; pass++) {
        curv = smoothCyclic(curv);
    }

    return curv;
}

/** Плавна S-крива върху [0,1]. */
function smooth01(v) {
    const t = v < 0 ? 0 : v > 1 ? 1 : v;

    return t * t * (3 - 2 * t);
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
 *          lateral: отместване от осевата линия в метри (+ = към нормалата, надясно)
 *          distance: изминато разстояние по обиколката в метри
 *          height: височина на асфалта под тази позиция
 */
export function projectOnTrack(track, x, z, hint = null, out = {}) {
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

    // Мутираме подадения обект (по подразбиране нов) — извикваме на всяка
    // физична стъпка, затова caller-ът подава постоянен обект без алокация/кадър.
    out.index = bestIndex;
    out.lateral = lateral;
    out.along = along;
    out.distance = bestIndex * track.spacing + along;
    out.height = heightAt(track, bestIndex, along);
    out.gradient = track.gradient[bestIndex];

    return out;
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
 * Напречният наклон на `along` метра след точка `index`, интерполиран между
 * съседните точки — по същата причина като heightAt: стъпаловидният bank по
 * рампите на банкинга местеше колата с ~26 cm на всяко прекосяване на ред.
 *
 * @param {Track} track
 * @param {number} index
 * @param {number} along
 * @returns {number}
 */
export function bankAt(track, index, along) {
    const { bankSlope, spacing, count } = track;

    const steps = along / spacing;
    const base = Math.floor(steps);
    const t = steps - base;

    const a = bankSlope[(((index + base) % count) + count) % count];
    const b = bankSlope[(((index + base + 1) % count) + count) % count];

    return a + (b - a) * t;
}

/**
 * Точките, в които трасето получава керб, като индексни интервали.
 *
 * Кербът е от ВЪТРЕШНАТА страна на завоя (там, където колата реже) —
 * знакът на кривината дава коя страна е това.
 *
 * @param {Track} track
 * @returns {Array<{from: number, to: number, side: number}>} side: +1 по нормалата
 *          (дясно), -1 срещу нея (ляво)
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
