<script setup>
// Класическа купа (чаша с дръжки) за подиум — злато/сребро/бронз според мястото.
// Inline SVG: остра на всякакъв размер, без външни картинки, в тъмната тема.
const props = defineProps({
    place: { type: Number, default: 1 }, // 1 | 2 | 3
});

const META = {
    1: { stops: ['#fef9c3', '#fbbf24', '#a16207'], edge: '#f59e0b', gem: '#fde047' },
    2: { stops: ['#fafafa', '#d4d4d8', '#71717a'], edge: '#a1a1aa', gem: '#e4e4e7' },
    3: { stops: ['#ffedd5', '#fb923c', '#9a3412'], edge: '#ea580c', gem: '#fdba74' },
};
const m = META[props.place] ?? META[1];
// Уникален id на градиента — три купи на един екран не се застъпват.
const gid = 'tg-' + props.place + '-' + Math.random().toString(36).slice(2, 8);
</script>

<template>
    <svg viewBox="0 0 48 60" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" :aria-label="`Купа за ${place}. място`">
        <defs>
            <linearGradient :id="gid" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" :stop-color="m.stops[0]" />
                <stop offset="0.5" :stop-color="m.stops[1]" />
                <stop offset="1" :stop-color="m.stops[2]" />
            </linearGradient>
        </defs>

        <!-- дръжки -->
        <path d="M13 13 C3 13 4 25 15 26" :stroke="m.edge" stroke-width="2.5" stroke-linecap="round" />
        <path d="M35 13 C45 13 44 25 33 26" :stroke="m.edge" stroke-width="2.5" stroke-linecap="round" />

        <!-- купа -->
        <path d="M12 8 H36 V14 C36 26 30 32 24 32 C18 32 12 26 12 14 Z" :fill="`url(#${gid})`" :stroke="m.edge" stroke-width="1" stroke-linejoin="round" />
        <!-- метален отблясък -->
        <path d="M16 11 C15 17 16 23 20 28" stroke="#ffffff" stroke-opacity="0.55" stroke-width="1.5" stroke-linecap="round" />

        <!-- стъбло -->
        <path d="M22 32 H26 V38 H22 Z" :fill="m.stops[1]" />
        <!-- основа (две стъпала) -->
        <path d="M17 38 H31 V42 H17 Z" :fill="`url(#${gid})`" :stroke="m.edge" stroke-width="0.75" stroke-linejoin="round" />
        <path d="M13 42 H35 V49 C35 50.1 34.1 51 33 51 H15 C13.9 51 13 50.1 13 49 Z" :fill="`url(#${gid})`" :stroke="m.edge" stroke-width="0.75" stroke-linejoin="round" />

        <!-- гравиран номер на мястото -->
        <text x="24" y="23" text-anchor="middle" font-size="13" font-weight="900" fill="#0a0a0a" fill-opacity="0.4" font-family="ui-sans-serif, system-ui, sans-serif">{{ place }}</text>
    </svg>
</template>
