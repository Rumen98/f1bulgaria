<script setup>
import { computed } from 'vue';

const props = defineProps({
    timeline: { type: Array, default: () => [] },
    highlightYear: { type: Number, default: null },
});

// Най-новите сезони първо.
const rows = computed(() => [...props.timeline].reverse());
</script>

<template>
    <section v-if="rows.length">
        <h2 class="mb-3 font-display text-lg font-bold text-white">Кариера по сезони</h2>
        <div class="overflow-hidden rounded-2xl border border-zinc-800 bg-zinc-900/40">
            <div
                v-for="row in rows"
                :key="row.year"
                class="flex items-center gap-4 border-b border-zinc-800/60 px-4 py-2.5 last:border-0 hover:bg-zinc-800/30"
                :class="{ 'bg-red-600/10 ring-1 ring-inset ring-red-600/40': row.year === highlightYear }"
            >
                <div class="flex w-14 items-center gap-1 font-bold tabular-nums text-white">
                    {{ row.year }}
                    <span v-if="row.is_champion" title="Световен шампион">⭐</span>
                </div>
                <div class="flex min-w-0 flex-1 items-center gap-2">
                    <span class="h-3 w-3 flex-shrink-0 rounded-full" :style="{ backgroundColor: row.color }" />
                    <span class="truncate text-zinc-300">{{ row.team ?? '—' }}</span>
                </div>
                <div v-if="row.wins" class="w-12 text-right text-xs font-semibold tabular-nums text-amber-400">{{ row.wins }} 🏆</div>
                <div v-else class="w-12" />
                <div v-if="row.podiums" class="hidden w-16 text-right text-xs tabular-nums text-zinc-500 sm:block">{{ row.podiums }} под.</div>
                <div v-else class="hidden w-16 sm:block" />
                <div class="w-20 text-right tabular-nums text-zinc-400">{{ row.points }} т.</div>
            </div>
        </div>
    </section>
</template>
