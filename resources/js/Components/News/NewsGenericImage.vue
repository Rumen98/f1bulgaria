<script setup>
import { computed } from 'vue';

const props = defineProps({
    classification: { type: String, default: 'other' },
    color: { type: String, default: '#e10600' },
    label: { type: String, default: 'F1' },
    title: { type: String, default: '' },
});

// Уникален id за defs (множество карти на страница).
let _uid = 0;
const gid = `ng-${++_uid}-${Math.round(Math.random() * 1e6)}`;

const accent = computed(
    () =>
        ({
            technical: '#2dd4bf',
            rumor: '#8b5cf6',
            business: '#f5c518',
            other: '#ff2d1f',
        })[props.classification] ?? props.color ?? '#e10600',
);

// 8 зъба на зъбно колело (за technical).
const gearTeeth = Array.from({ length: 8 }, (_, i) => i * 45);
</script>

<template>
    <div class="relative h-full w-full overflow-hidden">
        <svg viewBox="0 0 320 180" preserveAspectRatio="xMidYMid slice" class="h-full w-full" role="img" :aria-label="label">
            <defs>
                <linearGradient :id="`${gid}-bg`" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0%" stop-color="#18181b" />
                    <stop offset="100%" stop-color="#09090b" />
                </linearGradient>
                <radialGradient :id="`${gid}-glow`" cx="0.7" cy="0.4" r="0.8">
                    <stop offset="0%" :stop-color="accent" stop-opacity="0.22" />
                    <stop offset="100%" :stop-color="accent" stop-opacity="0" />
                </radialGradient>
                <filter :id="`${gid}-sh`" x="-30%" y="-30%" width="160%" height="160%">
                    <feDropShadow dx="0" dy="3" stdDeviation="4" flood-color="rgba(0,0,0,0.6)" />
                </filter>
            </defs>

            <rect width="320" height="180" :fill="`url(#${gid}-bg)`" />
            <rect width="320" height="180" :fill="`url(#${gid}-glow)`" />

            <!-- TECHNICAL: решетка + зъбно колело -->
            <g v-if="classification === 'technical'">
                <g stroke="#ffffff" stroke-opacity="0.05" stroke-width="1">
                    <line v-for="x in 8" :key="`vx${x}`" :x1="x * 40" y1="0" :x2="x * 40" y2="180" />
                    <line v-for="y in 4" :key="`hz${y}`" x1="0" :y1="y * 40" x2="320" :y2="y * 40" />
                </g>
                <g :transform="`translate(245 78)`" :filter="`url(#${gid}-sh)`">
                    <g :fill="accent" fill-opacity="0.9">
                        <rect v-for="a in gearTeeth" :key="a" x="-6" y="-44" width="12" height="16" :transform="`rotate(${a})`" rx="2" />
                    </g>
                    <circle r="30" :fill="accent" fill-opacity="0.9" />
                    <circle r="13" fill="#09090b" />
                </g>
            </g>

            <!-- RUMOR: въпросителна в драматична сянка -->
            <g v-else-if="classification === 'rumor'">
                <text x="232" y="118" text-anchor="middle" font-size="150" font-weight="900" fill="#000000" fill-opacity="0.55">?</text>
                <text x="226" y="112" text-anchor="middle" font-size="150" font-weight="900" :fill="accent" :filter="`url(#${gid}-sh)`">?</text>
            </g>

            <!-- BUSINESS: възходяща графика + барове -->
            <g v-else-if="classification === 'business'">
                <g :fill="accent" fill-opacity="0.18">
                    <rect x="150" y="120" width="20" height="40" rx="2" />
                    <rect x="186" y="96" width="20" height="64" rx="2" />
                    <rect x="222" y="70" width="20" height="90" rx="2" />
                    <rect x="258" y="44" width="20" height="116" rx="2" />
                </g>
                <polyline points="150,132 192,104 232,84 286,40" fill="none" :stroke="accent" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" :filter="`url(#${gid}-sh)`" />
                <circle cx="286" cy="40" r="6" :fill="accent" />
                <path d="M274 40 L286 40 L286 52" fill="none" :stroke="accent" stroke-width="4" stroke-linecap="round" />
            </g>

            <!-- OTHER: силует на каска + speed lines -->
            <g v-else>
                <g stroke="#ffffff" stroke-opacity="0.08" stroke-width="3" stroke-linecap="round">
                    <line x1="20" y1="60" x2="120" y2="60" />
                    <line x1="10" y1="90" x2="140" y2="90" />
                    <line x1="24" y1="120" x2="110" y2="120" />
                </g>
                <g :transform="`translate(232 92)`" :filter="`url(#${gid}-sh)`">
                    <path d="M-44 8 a48 44 0 0 1 92 -6 l4 22 a8 8 0 0 1 -8 9 l-30 0 l0 14 a10 10 0 0 1 -10 10 l-22 0 a14 14 0 0 1 -14 -14 Z" :fill="accent" fill-opacity="0.9" />
                    <path d="M-30 -2 a34 30 0 0 1 64 -2 l2 12 l-66 0 Z" fill="#09090b" fill-opacity="0.85" />
                </g>
            </g>
        </svg>

        <!-- „СЛУХ" badge за rumor -->
        <span
            v-if="classification === 'rumor'"
            class="absolute right-2 top-2 rounded bg-violet-500/90 px-2 py-0.5 text-xs font-bold uppercase tracking-wider text-white shadow"
        >Слух</span>

        <!-- Заглавие overlay -->
        <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/85 to-transparent p-3 pt-8">
            <span class="text-xs font-bold uppercase tracking-[0.2em]" :style="{ color: accent }">{{ label }}</span>
            <p v-if="title" class="mt-0.5 line-clamp-2 text-sm font-semibold text-white">{{ title }}</p>
        </div>
    </div>
</template>
