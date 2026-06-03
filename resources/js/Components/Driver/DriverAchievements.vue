<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    achievements: { type: Object, required: true },
    circuitWins: { type: Array, default: () => [] },
});

const svgs = import.meta.glob('../../../svg/circuits/*.svg', { query: '?raw', import: 'default', eager: true });

// Статичен track outline (без анимирания болид) за картите с победи.
const trackSvg = (slug) => {
    const key = Object.keys(svgs).find((p) => p.endsWith(`/${slug}.svg`));
    return key ? svgs[key].replace(/<circle[\s\S]*?<\/circle>/i, '') : null;
};

const cards = computed(() => [
    { label: 'Победи', value: props.achievements.wins, accent: 'text-amber-400', ring: 'border-amber-500/30', glow: 'from-amber-500/15' },
    { label: 'Подиуми', value: props.achievements.podiums, accent: 'text-zinc-200', ring: 'border-zinc-500/30', glow: 'from-zinc-400/10' },
    { label: 'Pole позиции', value: props.achievements.poles, accent: 'text-red-500', ring: 'border-red-600/30', glow: 'from-red-600/15' },
    { label: 'Най-бързи обиколки', value: props.achievements.fastest_laps, accent: 'text-purple-400', ring: 'border-purple-500/30', glow: 'from-purple-500/15' },
    { label: 'Състезания', value: props.achievements.races, accent: 'text-sky-400', ring: 'border-sky-500/30', glow: 'from-sky-500/10' },
    { label: 'Победи %', value: props.achievements.win_rate + '%', accent: 'text-emerald-400', ring: 'border-emerald-500/30', glow: 'from-emerald-500/15' },
]);

const hasRoute = (() => {
    try {
        return route().has('circuits.show');
    } catch (e) {
        return false;
    }
})();
</script>

<template>
    <section>
        <h2 class="mb-4 text-xl font-black text-white sm:text-2xl">🏅 Постижения</h2>

        <!-- Кариерни числа -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <div
                v-for="card in cards"
                :key="card.label"
                class="relative overflow-hidden rounded-2xl border bg-zinc-900/60 p-4 text-center"
                :class="card.ring"
            >
                <div class="pointer-events-none absolute inset-0 bg-gradient-to-br to-transparent" :class="card.glow" />
                <div class="relative text-3xl font-black tabular-nums sm:text-4xl" :class="card.accent">{{ card.value }}</div>
                <div class="relative mt-1 text-[11px] uppercase tracking-wide text-zinc-400">{{ card.label }}</div>
            </div>
        </div>

        <!-- Победи на писти -->
        <div v-if="circuitWins.length" class="mt-8">
            <h3 class="mb-3 text-lg font-bold text-white">Победи на писти</h3>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                <component
                    :is="hasRoute ? Link : 'div'"
                    v-for="win in circuitWins"
                    :key="win.circuit_slug"
                    :href="hasRoute ? route('circuits.show', win.circuit_slug) : undefined"
                    class="group relative flex flex-col rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 transition duration-200"
                    :class="hasRoute ? 'hover:border-red-600/50 hover:bg-zinc-900' : ''"
                >
                    <span class="absolute right-3 top-3 rounded-full bg-red-600/90 px-2 py-0.5 text-xs font-bold tabular-nums text-white">
                        {{ win.wins }}×
                    </span>
                    <div
                        v-if="trackSvg(win.circuit_slug)"
                        class="mb-2 h-20 text-zinc-300 transition group-hover:text-white [&_svg]:mx-auto [&_svg]:h-20 [&_svg]:w-auto [&_svg]:max-w-full"
                        v-html="trackSvg(win.circuit_slug)"
                    />
                    <div v-else class="mb-2 flex h-20 items-center justify-center text-2xl">🏁</div>
                    <div class="mt-auto truncate text-sm font-semibold text-white" :title="win.circuit">{{ win.circuit }}</div>
                </component>
            </div>
        </div>
    </section>
</template>
