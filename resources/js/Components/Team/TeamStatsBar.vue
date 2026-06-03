<script setup>
import { computed } from 'vue';

const props = defineProps({
    stats: { type: Object, required: true },
});

const items = computed(() => [
    { label: 'Точки', value: props.stats.points },
    { label: 'Победи', value: props.stats.wins },
    { label: 'Подиуми', value: props.stats.podiums },
    { label: 'Pole', value: props.stats.poles },
    { label: 'Най-бързи обиколки', value: props.stats.fastest_laps },
    { label: 'Отпадания', value: props.stats.dnfs },
]);
</script>

<template>
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <div v-for="item in items" :key="item.label" class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 text-center">
            <div class="text-2xl font-black tabular-nums text-white">{{ item.value }}</div>
            <div class="mt-1 text-xs uppercase tracking-wide text-zinc-500">{{ item.label }}</div>
        </div>
    </div>
</template>
