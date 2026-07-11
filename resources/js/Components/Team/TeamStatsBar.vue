<script setup>
import StatTile from '@/Components/UI/StatTile.vue';
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
        <StatTile v-for="item in items" :key="item.label" :label="item.label">{{ item.value }}</StatTile>
    </div>
</template>
