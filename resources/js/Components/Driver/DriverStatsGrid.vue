<script setup>
import { computed } from 'vue';

const props = defineProps({
    season: { type: Object, required: true },
    allTime: { type: Object, required: true },
    seasonYear: Number,
});

const seasonItems = computed(() => [
    { label: 'Позиция', value: props.season.position ? 'P' + props.season.position : '—' },
    { label: 'Точки', value: props.season.points },
    { label: 'Победи', value: props.season.wins },
    { label: 'Подиуми', value: props.season.podiums },
    { label: 'Pole', value: props.season.poles },
    { label: 'Най-бързи', value: props.season.fastest_laps },
    { label: 'Отпадания', value: props.season.dnfs },
]);

const allTimeItems = computed(() => [
    { label: 'Точки', value: props.allTime.points },
    { label: 'Победи', value: props.allTime.wins },
    { label: 'Подиуми', value: props.allTime.podiums },
    { label: 'Pole', value: props.allTime.poles },
    { label: 'Състезания', value: props.allTime.races },
    { label: 'Сезони', value: props.allTime.seasons },
]);
</script>

<template>
    <div class="space-y-6">
        <div>
            <h2 class="mb-3 text-lg font-bold text-white">Сезон {{ seasonYear }}</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
                <div v-for="item in seasonItems" :key="item.label" class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-3 text-center">
                    <div class="text-xl font-black tabular-nums text-white">{{ item.value }}</div>
                    <div class="mt-1 text-[11px] uppercase tracking-wide text-zinc-500">{{ item.label }}</div>
                </div>
            </div>
        </div>

        <div>
            <h2 class="mb-3 text-lg font-bold text-white">За всички сезони <span class="text-xs font-normal text-zinc-500">(в нашата база)</span></h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <div v-for="item in allTimeItems" :key="item.label" class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-3 text-center">
                    <div class="text-xl font-black tabular-nums text-white">{{ item.value }}</div>
                    <div class="mt-1 text-[11px] uppercase tracking-wide text-zinc-500">{{ item.label }}</div>
                </div>
            </div>
        </div>
    </div>
</template>
