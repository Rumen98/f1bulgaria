<script setup>
import { computed } from 'vue';

const props = defineProps({
    records: { type: Object, required: true },
});

const cards = computed(() => [
    { icon: '🏆', label: 'Най-много победи', rec: props.records.most_wins },
    { icon: '⏱️', label: 'Най-много pole', rec: props.records.most_poles },
    { icon: '🔥', label: 'Най-много най-бързи обиколки', rec: props.records.most_fastest_laps },
]);
</script>

<template>
    <div>
        <h2 class="mb-3 text-lg font-bold text-white">Рекорди</h2>
        <div class="grid gap-3 sm:grid-cols-3">
            <div v-for="card in cards" :key="card.label" class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-4">
                <div class="text-xs uppercase tracking-wide text-zinc-500">{{ card.icon }} {{ card.label }}</div>
                <div v-if="card.rec" class="mt-1">
                    <div class="font-bold text-white">{{ card.rec.name }}</div>
                    <div class="text-sm tabular-nums text-red-500">{{ card.rec.count }}×</div>
                </div>
                <div v-else class="mt-1 text-sm text-zinc-600">—</div>
            </div>
        </div>
    </div>
</template>
