/** Официалното F1 червено — fallback за отбор без зададен color_hex. */
export const TEAM_COLOR_FALLBACK = '#e10600';

/** Неутрална точка (zinc-600) за конструктор без цвят от данните. */
export const NEUTRAL_DOT_COLOR = '#52525b';

/**
 * Подиум оцветяване (злато/сребро/бронз) за позиция в класиране.
 */
export function podiumClass(position) {
    if (position === 1) {
        return 'text-amber-300';
    }
    if (position === 2) {
        return 'text-zinc-300';
    }
    if (position === 3) {
        return 'text-orange-400';
    }
    return '';
}
