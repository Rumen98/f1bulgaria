<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Блокът с данните от последния кръг на началната страница.
 *
 * Стои високо нарочно. Разделът „Данни" е нов и никой не го търси по име, а
 * материалът има срок — чете се в дните след състезанието. Ако човек трябва
 * първо да намери пункт в менюто, няма да го намери.
 *
 * Показва две числа и три реда текст: достатъчно, за да си струва кликът,
 * недостатъчно, за да го замени.
 */
const props = defineProps({
    recap: { type: Object, required: true },
});

const tiles = computed(() =>
    [
        props.recap.top_speed
            ? { label: 'Най-висока скорост', value: `${props.recap.top_speed.kmh} км/ч`, note: props.recap.top_speed.name }
            : null,
        props.recap.fastest_lap
            ? { label: 'Най-бърза обиколка', value: props.recap.fastest_lap.display, note: props.recap.fastest_lap.name }
            : null,
    ].filter(Boolean),
);
</script>

<template>
    <section class="mt-10">
        <div class="mb-3 flex items-baseline justify-between gap-4">
            <h2 class="font-display text-lg font-bold text-white">Какво казват данните</h2>
            <Link :href="route('racedata.index')" class="text-sm font-medium text-red-500 transition hover:text-red-400">
                Всички анализи
            </Link>
        </div>

        <Link
            :href="route('racedata.show', recap.race_id)"
            class="block rounded-2xl border border-zinc-800 bg-zinc-900/60 p-5 transition duration-200 hover:border-red-600/50 hover:bg-zinc-900"
        >
            <p class="text-xs uppercase tracking-wide text-zinc-500">
                <template v-if="recap.round">Кръг {{ recap.round }} · </template>{{ recap.race }}
            </p>

            <h3 class="mt-1 font-display text-xl font-black text-white sm:text-2xl">{{ recap.headline }}</h3>

            <div class="mt-4 grid gap-4 sm:grid-cols-[1fr_auto] sm:items-end">
                <ul class="space-y-1.5 text-sm text-zinc-300">
                    <li v-for="(fact, i) in recap.bullets" :key="i" class="flex gap-2">
                        <span class="mt-2 h-1 w-1 shrink-0 rounded-full bg-red-600" aria-hidden="true" />
                        <span>{{ fact }}</span>
                    </li>
                </ul>

                <div v-if="tiles.length" class="flex gap-3">
                    <div
                        v-for="tile in tiles"
                        :key="tile.label"
                        class="min-w-[7.5rem] rounded-xl border border-zinc-800 bg-zinc-950/60 p-3"
                    >
                        <p class="font-display text-xl font-black tabular-nums text-white">{{ tile.value }}</p>
                        <p class="mt-0.5 truncate text-xs text-zinc-500" :title="tile.note">{{ tile.note }}</p>
                    </div>
                </div>
            </div>

            <p class="mt-4 text-sm font-medium text-red-500">Виж графиките →</p>
        </Link>
    </section>
</template>
