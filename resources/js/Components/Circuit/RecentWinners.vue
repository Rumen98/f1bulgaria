<script setup>
defineProps({
    winners: { type: Array, default: () => [] },
});
</script>

<template>
    <div>
        <h2 class="mb-3 text-lg font-bold text-white">Последни победители</h2>
        <div v-if="winners.length === 0" class="rounded-xl border border-dashed border-zinc-800 p-6 text-center text-sm text-zinc-500">
            Няма данни.
        </div>
        <div v-else class="overflow-hidden rounded-xl border border-zinc-800">
            <table class="w-full text-sm">
                <tbody class="divide-y divide-zinc-800">
                    <tr v-for="(w, i) in winners" :key="i" class="bg-zinc-900/40 transition hover:bg-zinc-800/50">
                        <td class="px-4 py-2.5 font-bold tabular-nums text-zinc-400">{{ w.year }}</td>
                        <td class="px-4 py-2.5 font-semibold text-white">{{ w.driver }}</td>
                        <td class="px-4 py-2.5 text-right text-zinc-300">
                            <span class="inline-flex items-center gap-2">
                                <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: w.color ?? '#52525b' }" />
                                {{ w.team ?? '—' }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
