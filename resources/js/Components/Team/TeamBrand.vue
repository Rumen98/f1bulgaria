<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import TeamMonogram from '@/Components/Team/TeamMonogram.vue';

const props = defineProps({
    name: { type: String, default: '' },
    color: { type: String, default: '#e10600' },
    slug: { type: String, default: '' },
    // 'emblem' = квадратен badge (за списъци/карти/hero); 'wordmark' = широк лого-лок.
    variant: { type: String, default: 'emblem' },
    size: { type: String, default: 'md' }, // 'sm' | 'md' | 'lg'
});

// Уникален id за градиента (множество инстанции на една страница).
let _uid = 0;
const gid = `tb-${++_uid}-${Math.round(Math.random() * 1e6)}`;

const brands = computed(() => usePage().props.teamBrands ?? {});
const brand = computed(() => brands.value[props.slug] ?? null);
const hasBrand = computed(() => brand.value !== null);

const primary = computed(() => brand.value?.colors?.[0] ?? props.color ?? '#e10600');
const secondary = computed(() => brand.value?.colors?.[1] ?? '#0a0a0a');
const shape = computed(() => brand.value?.shape ?? 'circle');
const font = computed(() => brand.value?.font ?? 'sans');

const wordmark = computed(() => (props.name ?? '').toUpperCase());

// До 2 инициала за вътре в badge-а (Ferrari → F, Red Bull Racing → RB).
const initials = computed(() => {
    const words = (props.name ?? '').trim().split(/\s+/).filter(Boolean);
    if (words.length === 0) {
        return '?';
    }
    if (words.length === 1) {
        return words[0].charAt(0).toUpperCase();
    }
    return (words[0].charAt(0) + words[1].charAt(0)).toUpperCase();
});

const fontStyle = computed(() => (font.value === 'italic' ? 'italic' : 'normal'));
const fontWeight = computed(() => (font.value === 'sans-bold' || font.value === 'italic' ? 800 : 700));
const fontFamily = computed(() =>
    font.value === 'serif' ? 'Georgia, "Times New Roman", serif' : 'system-ui, -apple-system, sans-serif',
);
const letterSpacing = computed(() => brand.value?.letterSpacing ?? '0.08em');

// Текстов контраст върху badge-а: ако primary е много светъл → тъмен текст.
const badgeTextColor = computed(() => {
    const hex = primary.value.replace('#', '');
    if (hex.length < 6) {
        return '#ffffff';
    }
    const r = parseInt(hex.slice(0, 2), 16);
    const g = parseInt(hex.slice(2, 4), 16);
    const b = parseInt(hex.slice(4, 6), 16);
    const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
    return luminance > 0.6 ? '#0a0a0a' : '#ffffff';
});

// Геометричната форма на badge-а (центрирана в 80x80 регион).
const badgePath = computed(() => {
    switch (shape.value) {
        case 'shield':
            return 'M40 6 L72 18 V42 C72 60 58 70 40 76 C22 70 8 60 8 42 V18 Z';
        case 'hexagon':
            return 'M40 6 L70 23 V57 L40 74 L10 57 V23 Z';
        case 'angular':
            return 'M16 8 H72 L64 72 H8 Z';
        case 'classic':
            return 'M14 12 H66 A8 8 0 0 1 74 20 V60 A8 8 0 0 1 66 68 H14 A8 8 0 0 1 6 60 V20 A8 8 0 0 1 14 12 Z';
        default: // circle
            return 'M40 8 A32 32 0 1 1 39.9 8 Z';
    }
});

const heightClass = computed(
    () => ({ sm: 'h-8', md: 'h-16', lg: 'h-28' })[props.size] ?? 'h-16',
);
</script>

<template>
    <!-- Wordmark вариант: широк лого-лок (badge + текст) за банери/hero. -->
    <svg
        v-if="hasBrand && variant === 'wordmark'"
        :class="heightClass"
        viewBox="0 0 300 80"
        class="w-auto max-w-full"
        role="img"
        :aria-label="name"
        preserveAspectRatio="xMinYMid meet"
    >
        <defs>
            <linearGradient :id="gid" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" :stop-color="primary" />
                <stop offset="100%" :stop-color="secondary" />
            </linearGradient>
            <filter :id="`${gid}-sh`" x="-20%" y="-20%" width="140%" height="140%">
                <feDropShadow dx="0" dy="1" stdDeviation="1.5" flood-color="rgba(0,0,0,0.45)" />
            </filter>
        </defs>
        <path :d="badgePath" :fill="`url(#${gid})`" stroke="rgba(255,255,255,0.18)" stroke-width="1.5" :filter="`url(#${gid}-sh)`" />
        <text x="40" y="44" text-anchor="middle" dominant-baseline="central" font-size="30" font-weight="800" :fill="badgeTextColor" :font-family="fontFamily">{{ initials }}</text>
        <text
            x="96" y="44" dominant-baseline="central" :fill="primary" font-size="34"
            :font-weight="fontWeight" :font-style="fontStyle" :font-family="fontFamily"
            :style="{ letterSpacing }" textLength="196" lengthAdjust="spacingAndGlyphs"
        >{{ wordmark }}</text>
    </svg>

    <!-- Emblem вариант: квадратен badge (заменя монограмата). -->
    <svg
        v-else-if="hasBrand"
        viewBox="0 0 80 80"
        class="h-full w-full"
        role="img"
        :aria-label="name"
    >
        <defs>
            <linearGradient :id="gid" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" :stop-color="primary" />
                <stop offset="100%" :stop-color="secondary" />
            </linearGradient>
            <filter :id="`${gid}-sh`" x="-20%" y="-20%" width="140%" height="140%">
                <feDropShadow dx="0" dy="1" stdDeviation="1.5" flood-color="rgba(0,0,0,0.45)" />
            </filter>
        </defs>
        <path :d="badgePath" :fill="`url(#${gid})`" stroke="rgba(255,255,255,0.18)" stroke-width="1.5" :filter="`url(#${gid}-sh)`" />
        <text x="40" y="43" text-anchor="middle" dominant-baseline="central" font-size="30" font-weight="800" :fill="badgeTextColor" :font-family="fontFamily">{{ initials }}</text>
    </svg>

    <!-- Fallback за легендарни отбори без brand конфиг. -->
    <TeamMonogram v-else :name="name" :color="color" />
</template>
