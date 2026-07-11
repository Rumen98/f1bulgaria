<script setup>
import { TEAM_COLOR_FALLBACK } from '@/utils/racing';
import { computed } from 'vue';

const props = defineProps({
    h2h: { type: Object, required: true },
    driverName: String,
    color: { type: String, default: TEAM_COLOR_FALLBACK },
});

const rows = computed(() => [
    { label: 'Състезания', win: props.h2h.race_wins, loss: props.h2h.race_losses },
    { label: 'Квалификации', win: props.h2h.quali_wins, loss: props.h2h.quali_losses },
]);

const pct = (win, loss) => {
    const total = win + loss;
    return total === 0 ? 50 : Math.round((win / total) * 100);
};
</script>

<template>
    <div>
        <h2 class="mb-1 text-lg font-bold text-white">Срещу съотборника</h2>
        <p class="mb-3 text-sm text-zinc-500">
            {{ driverName }} <span class="text-zinc-600">срещу</span> {{ h2h.teammate ?? '—' }}
            <span class="text-xs text-zinc-600">(квалификации по стартова позиция)</span>
        </p>

        <div v-if="!h2h.teammate" class="rounded-xl border border-dashed border-zinc-800 p-4 text-sm text-zinc-500">
            Няма съотборник за сравнение.
        </div>

        <div v-else class="space-y-4">
            <div v-for="row in rows" :key="row.label">
                <div class="mb-1 flex justify-between text-sm">
                    <span class="font-bold tabular-nums text-white">{{ row.win }}</span>
                    <span class="text-zinc-500">{{ row.label }}</span>
                    <span class="font-bold tabular-nums text-zinc-400">{{ row.loss }}</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-zinc-800">
                    <div class="h-full rounded-full transition-all duration-500" :style="{ width: pct(row.win, row.loss) + '%', backgroundColor: color }" />
                </div>
            </div>
        </div>
    </div>
</template>
