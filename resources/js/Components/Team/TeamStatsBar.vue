<script setup>
import { computed } from 'vue';

const props = defineProps({
    stats: { type: Object, required: true },
});

// Без all-time точки (точковите системи са се менили — подвеждащо). Показваме
// титли (когато са въведени) + честни кумулативни статистики.
const items = computed(() => [
    ...(props.stats.championships !== undefined ? [{ label: 'Титли', value: props.stats.championships }] : []),
    { label: 'Победи', value: props.stats.wins },
    { label: 'Подиуми', value: props.stats.podiums },
    { label: 'Pole', value: props.stats.poles },
    { label: 'Старта', value: props.stats.races },
    { label: 'Сезони', value: props.stats.seasons },
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
