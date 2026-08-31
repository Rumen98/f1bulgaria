<script setup>
import { TEAM_COLOR_FALLBACK } from '@/utils/racing';
import { hasRoute } from '@/utils/routes';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const canOpenDriver = computed(() => hasRoute('drivers.show'));

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
        <!-- flex + gap, а не текстови възли: Vue свива празното пространство
             между елементи с нов ред и имената се слепваха едно за друго. -->
        <p class="mb-3 flex flex-wrap items-baseline gap-x-1.5 text-sm text-zinc-500">
            <span>{{ driverName }}</span>
            <span class="text-zinc-600">срещу</span>
            <Link
                v-if="canOpenDriver && h2h.teammate_slug"
                :href="route('drivers.show', h2h.teammate_slug)"
                class="text-zinc-300 transition hover:text-red-400"
            >{{ h2h.teammate }}</Link>
            <span v-else>{{ h2h.teammate ?? '—' }}</span>
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
