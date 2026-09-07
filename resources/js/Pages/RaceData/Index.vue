<script setup>
import EmptyState from '@/Components/UI/EmptyState.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Link } from '@inertiajs/vue3';

defineProps({
    races: { type: Array, required: true },
    seasons: { type: Array, default: () => [] },
    season: { type: Number, default: null },
});
</script>

<template>
    <PublicLayout>
        <header class="mb-6">
            <h1 class="font-display text-2xl font-black text-white sm:text-3xl">Данните от състезанията</h1>
            <p class="mt-2 max-w-prose text-zinc-400">
                Няколко часа след всяко състезание тук излиза какво показват данните: стратегиите по гуми,
                темпото по обиколки, спечелените позиции и как се е разместил шампионатът.
            </p>
        </header>

        <!-- Сезоните са изгледи на един и същ раздел, затова са ленти, а не
             отделни страници. Показват се едва когато има повече от един. -->
        <nav v-if="seasons.length > 1" aria-label="Сезон" class="mb-6 flex flex-wrap gap-2">
            <Link
                v-for="year in seasons"
                :key="year"
                :href="route('racedata.index', { sezon: year })"
                class="rounded-lg border px-3 py-1.5 text-sm font-medium tabular-nums transition"
                :class="year === season
                    ? 'border-red-600 bg-red-600/10 text-white'
                    : 'border-zinc-800 text-zinc-400 hover:border-red-600/50 hover:text-zinc-200'"
            >
                {{ year }}
            </Link>
        </nav>

        <EmptyState v-if="!races.length">
            Още няма готов анализ. Първият излиза няколко часа след следващото състезание.
        </EmptyState>

        <ul v-else class="grid gap-4 sm:grid-cols-2">
            <li v-for="race in races" :key="race.id">
                <Link
                    :href="route('racedata.show', race.id)"
                    class="flex h-full flex-col rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 transition duration-200 hover:border-red-600/50 hover:bg-zinc-900"
                >
                    <div class="flex items-baseline justify-between gap-3">
                        <h2 class="font-display text-lg font-bold text-white">{{ race.name }}</h2>
                        <span class="shrink-0 font-mono text-xs tabular-nums text-zinc-600">{{ race.date }}</span>
                    </div>

                    <p class="mt-0.5 text-xs uppercase tracking-wide text-zinc-500">
                        <template v-if="race.round">Кръг {{ race.round }}</template>
                        <template v-if="race.round && race.circuit"> · </template>
                        {{ race.circuit }}
                    </p>

                    <p v-if="race.winner" class="mt-3 flex items-center gap-2 text-sm text-zinc-300">
                        <span class="h-4 w-1 rounded-full" :style="{ backgroundColor: race.winner.colour }" aria-hidden="true" />
                        {{ race.winner.name }}
                    </p>

                    <ul v-if="race.bullets.length" class="mt-2 space-y-1 text-sm text-zinc-500">
                        <li v-for="(fact, i) in race.bullets" :key="i" class="line-clamp-2">{{ fact }}</li>
                    </ul>
                </Link>
            </li>
        </ul>

        <p class="mt-8 text-xs text-zinc-600">
            Данни:
            <a href="https://openf1.org" rel="noopener nofollow" class="transition hover:text-zinc-400">openf1.org</a>
            · CC BY-NC-SA 4.0. Изчисленията са наши.
        </p>
    </PublicLayout>
</template>
