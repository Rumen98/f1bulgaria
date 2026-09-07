/**
 * Гумените състави, както ги връща OpenF1 (ключовете са с ГЛАВНИ букви).
 *
 * Едно място за целия сайт: таймингът на живо показва кръгчето с буквата, а
 * стратегията в „Данни" рисува лентата — двете трябва да са в един и същ цвят,
 * иначе същата гума изглежда различно на две страници.
 */
const COMPOUNDS = {
    SOFT: { letter: 'S', label: 'Мека', text: 'text-red-500', border: 'border-red-500', hex: '#ff2d1f' },
    MEDIUM: { letter: 'M', label: 'Средна', text: 'text-yellow-400', border: 'border-yellow-400', hex: '#facc15' },
    HARD: { letter: 'H', label: 'Твърда', text: 'text-zinc-200', border: 'border-zinc-200', hex: '#e4e4e7' },
    INTERMEDIATE: { letter: 'I', label: 'Междинна', text: 'text-emerald-400', border: 'border-emerald-400', hex: '#34d399' },
    WET: { letter: 'W', label: 'Дъждовна', text: 'text-sky-400', border: 'border-sky-400', hex: '#38bdf8' },
};

/** Пълното описание на състав, или null за непознат. */
export function tyre(compound) {
    return COMPOUNDS[String(compound ?? '').toUpperCase()] ?? null;
}

/** Цветът за рисуване. Непознат състав е сив, а не невидим. */
export function tyreHex(compound) {
    return tyre(compound)?.hex ?? '#52525b';
}

/** Съставите, които изобщо се срещат — за легенди. */
export function tyreLegend(compounds) {
    const seen = [];

    for (const compound of compounds) {
        const key = String(compound ?? '').toUpperCase();

        if (COMPOUNDS[key] && !seen.includes(key)) {
            seen.push(key);
        }
    }

    return seen.map((key) => ({ key, ...COMPOUNDS[key] }));
}
