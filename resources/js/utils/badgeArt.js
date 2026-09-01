/**
 * Визуалната идентичност на всяка значка — споделена между картите в профила
 * (BadgeCard) и поздравлението при спечелване (BadgeAwardToast).
 *
 * Цветовете следват езика на Ф1: лилаво = pole/най-бърз сектор, злато =
 * шампион, зелено = лично постижение, червено = брандът.
 */
export const BADGE_ART = {
    'first-prediction': {
        emoji: '🏁',
        circle: 'from-emerald-500/90 to-emerald-700',
        glow: 'shadow-emerald-500/25',
        border: 'border-emerald-500/40',
        tint: 'bg-emerald-500/5',
    },
    'perfect-podium': {
        emoji: '🥇',
        circle: 'from-amber-400/90 to-orange-600',
        glow: 'shadow-amber-500/25',
        border: 'border-amber-500/40',
        tint: 'bg-amber-500/5',
    },
    'high-scorer': {
        emoji: '🎯',
        circle: 'from-red-500/90 to-red-800',
        glow: 'shadow-red-500/25',
        border: 'border-red-500/40',
        tint: 'bg-red-500/5',
    },
    'pole-master': {
        emoji: '⏱️',
        circle: 'from-purple-500/90 to-fuchsia-700',
        glow: 'shadow-purple-500/25',
        border: 'border-purple-500/40',
        tint: 'bg-purple-500/5',
    },
    'streak-3': {
        emoji: '🔥',
        circle: 'from-orange-400/90 to-red-600',
        glow: 'shadow-orange-500/25',
        border: 'border-orange-500/40',
        tint: 'bg-orange-500/5',
    },
    'season-champion': {
        emoji: '👑',
        circle: 'from-yellow-300/90 to-amber-600',
        glow: 'shadow-yellow-400/30',
        border: 'border-yellow-400/40',
        tint: 'bg-yellow-400/5',
    },
};

export const BADGE_ART_FALLBACK = {
    emoji: '🏅',
    circle: 'from-zinc-500/90 to-zinc-700',
    glow: 'shadow-zinc-500/20',
    border: 'border-zinc-600/40',
    tint: 'bg-zinc-500/5',
};

export const badgeArt = (slug) => BADGE_ART[slug] ?? BADGE_ART_FALLBACK;
