<script setup>
import { computed } from 'vue';

const props = defineProps({
    // breakdown_json от PredictionScore — ключове p1/p2/p3/pole/fastest_lap/dnf/safety_car.
    breakdown: { type: Object, default: null },
    total: { type: Number, default: 0 },
});

// Редът следва формата за прогноза, за да е разпознаваем.
const LABELS = [
    ['p1', 'Победител'],
    ['p2', 'Второ място'],
    ['p3', 'Трето място'],
    ['pole', 'Pole позиция'],
    ['fastest_lap', 'Най-бърза обиколка'],
    ['dnf', 'Брой отпаднали'],
    ['safety_car', 'Safety car'],
];

// Нескорирана прогноза идва без breakdown — компонентът не бива да гърми.
const rows = computed(() => {
    if (!props.breakdown) {
        return [];
    }

    return LABELS
        .filter(([key]) => props.breakdown[key] !== undefined)
        .map(([key, label]) => ({ key, label, points: Number(props.breakdown[key]) || 0 }));
});
</script>

<template>
    <div v-if="rows.length" class="overflow-hidden rounded-lg border border-zinc-800">
        <ul class="divide-y divide-zinc-800/60">
            <li
                v-for="row in rows"
                :key="row.key"
                class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                :class="row.points > 0 ? 'bg-emerald-950/20' : 'bg-zinc-900/40'"
            >
                <span class="flex min-w-0 items-center gap-2">
                    <span
                        class="shrink-0 text-xs"
                        :class="row.points > 0 ? 'text-emerald-400' : 'text-zinc-600'"
                        aria-hidden="true"
                    >{{ row.points > 0 ? '✓' : '✗' }}</span>
                    <span class="truncate" :class="row.points > 0 ? 'text-zinc-200' : 'text-zinc-500'">{{ row.label }}</span>
                </span>
                <span
                    class="shrink-0 font-bold tabular-nums"
                    :class="row.points > 0 ? 'text-emerald-400' : 'text-zinc-600'"
                >{{ row.points > 0 ? '+' + row.points : '0' }}</span>
            </li>
        </ul>
        <div class="flex items-center justify-between bg-zinc-900/80 px-3 py-2 text-sm font-bold">
            <span class="text-zinc-400">Общо</span>
            <span class="tabular-nums text-white">{{ total }} т.</span>
        </div>
    </div>
</template>
