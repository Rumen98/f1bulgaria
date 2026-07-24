<script setup>
import TableShell from '@/Components/UI/TableShell.vue';

defineProps({
    results: { type: Array, default: () => [] },
});
</script>

<template>
    <div class="min-w-0">
        <h2 class="mb-3 text-lg font-bold text-white">Последни резултати</h2>

        <div v-if="results.length === 0" class="rounded-xl border border-dashed border-zinc-800 p-6 text-center text-sm text-zinc-500">
            Няма резултати.
        </div>

        <TableShell v-else>
            <table class="w-full text-sm">
                <thead class="bg-zinc-900 text-left text-xs uppercase tracking-wide text-zinc-500">
                    <tr>
                        <th class="px-4 py-2">Състезание</th>
                        <th class="px-4 py-2 text-center">Поз.</th>
                        <th class="px-4 py-2 text-right">Точки</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800">
                    <tr v-for="(r, i) in results" :key="i" class="bg-zinc-900/40 transition hover:bg-zinc-800/50">
                        <td class="px-4 py-2 text-zinc-200">
                            {{ r.race }}
                            <span v-if="r.fastest_lap" title="Най-бърза обиколка">🔥</span>
                        </td>
                        <td class="px-4 py-2 text-center font-bold tabular-nums" :class="r.position ? 'text-zinc-200' : 'text-red-500'">
                            {{ r.position ?? 'DNF' }}
                        </td>
                        <td class="px-4 py-2 text-right font-medium tabular-nums text-white">{{ r.points }}</td>
                    </tr>
                </tbody>
            </table>
        </TableShell>
    </div>
</template>
