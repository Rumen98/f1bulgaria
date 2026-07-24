<script setup>
import EmptyState from '@/Components/UI/EmptyState.vue';
import TableShell from '@/Components/UI/TableShell.vue';
import { NEUTRAL_DOT_COLOR } from '@/utils/racing';

defineProps({
    winners: { type: Array, default: () => [] },
});
</script>

<template>
    <div class="min-w-0">
        <h2 class="mb-3 font-display text-lg font-bold text-white">Последни победители</h2>
        <EmptyState v-if="winners.length === 0">
            Няма данни.
        </EmptyState>
        <TableShell v-else>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-zinc-800/60">
                    <tr v-for="(w, i) in winners" :key="i" class="bg-zinc-900/40 transition hover:bg-zinc-800/40">
                        <td class="px-4 py-2.5 font-bold tabular-nums text-zinc-400">{{ w.year }}</td>
                        <td class="px-4 py-2.5 font-semibold text-white">{{ w.driver }}</td>
                        <td class="px-4 py-2.5 text-right text-zinc-300">
                            <span class="inline-flex items-center gap-2">
                                <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: w.color ?? NEUTRAL_DOT_COLOR }" />
                                {{ w.team ?? '—' }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </TableShell>
    </div>
</template>
