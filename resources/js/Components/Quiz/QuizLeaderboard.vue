<script setup>
import TableShell from '@/Components/UI/TableShell.vue';
import { podiumClass } from '@/utils/racing';
import { Link } from '@inertiajs/vue3';

defineProps({
    rows: { type: Array, default: () => [] },
    // Подсветва реда на текущия потребител.
    currentUserId: { type: Number, default: null },
});
</script>

<template>
    <section v-if="rows.length">
        <h2 class="mb-3 font-display text-lg font-black uppercase tracking-wide text-white">Класация на куиза</h2>

        <TableShell>
            <table class="w-full text-sm">
                <thead class="bg-zinc-900/80 text-xs uppercase tracking-wide text-zinc-500">
                    <tr>
                        <th class="w-10 px-2 py-2 text-left sm:px-3">#</th>
                        <th class="px-2 py-2 text-left sm:px-3">Играч</th>
                        <th class="px-2 py-2 text-right sm:px-3">Точки</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800/60">
                    <tr
                        v-for="row in rows"
                        :key="row.id"
                        :class="row.id === currentUserId ? 'bg-red-950/20' : ''"
                    >
                        <td class="px-2 py-2.5 font-black tabular-nums sm:px-3" :class="podiumClass(row.position) || 'text-zinc-500'">
                            {{ row.position }}
                        </td>
                        <td class="px-2 py-2.5 font-semibold sm:px-3">
                            <Link :href="route('profiles.show', row.id)" class="text-white transition hover:text-red-400">
                                {{ row.name }}
                            </Link>
                            <span v-if="row.id === currentUserId" class="ml-2 rounded bg-red-600 px-1.5 py-0.5 text-[10px] font-black uppercase text-white">ти</span>
                        </td>
                        <td class="px-2 py-2.5 text-right font-display font-black tabular-nums text-white sm:px-3">{{ row.points }}</td>
                    </tr>
                </tbody>
            </table>
        </TableShell>

        <p class="mt-2 text-xs text-zinc-500">
            Точка се дава при първия верен отговор на всеки въпрос. Без награди — само за честта.
        </p>
    </section>
</template>
