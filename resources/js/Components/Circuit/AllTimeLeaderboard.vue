<script setup>
defineProps({
    standings: { type: Array, default: () => [] },
});

const rowClass = (pos) => ({
    1: 'bg-gradient-to-r from-amber-500/20 to-transparent',
    2: 'bg-gradient-to-r from-zinc-400/15 to-transparent',
    3: 'bg-gradient-to-r from-orange-700/15 to-transparent',
})[pos] ?? '';

const posClass = (pos) => ({
    1: 'text-amber-400',
    2: 'text-zinc-300',
    3: 'text-orange-500',
})[pos] ?? 'text-zinc-500';
</script>

<template>
    <section class="overflow-hidden rounded-2xl border border-zinc-800 bg-zinc-900/40">
        <div class="border-b border-zinc-800 bg-black/30 px-5 py-4">
            <h2 class="text-lg font-black text-white">🏆 All-time класиране</h2>
            <p class="text-sm text-zinc-500">Най-успешните пилоти на тази писта (всички сезони в базата)</p>
        </div>

        <div v-if="standings.length === 0" class="p-8 text-center text-sm text-zinc-500">
            Все още няма данни за тази писта.
        </div>

        <table v-else class="w-full text-sm">
            <thead class="text-left text-xs uppercase tracking-wide text-zinc-500">
                <tr>
                    <th class="px-5 py-2 w-12">#</th>
                    <th class="px-5 py-2">Пилот</th>
                    <th class="px-5 py-2 text-center">Старт.</th>
                    <th class="px-5 py-2 text-center">Победи</th>
                    <th class="px-5 py-2 text-right">Точки</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-800">
                <tr
                    v-for="row in standings"
                    :key="row.code"
                    class="transition duration-200 hover:bg-zinc-800/40"
                    :class="rowClass(row.position)"
                >
                    <td class="px-5 py-2.5 text-lg font-black tabular-nums" :class="posClass(row.position)">{{ row.position }}</td>
                    <td class="px-5 py-2.5 font-semibold text-white">
                        {{ row.name }} <span class="ml-1 text-xs font-normal text-zinc-500">{{ row.code }}</span>
                    </td>
                    <td class="px-5 py-2.5 text-center tabular-nums text-zinc-400">{{ row.races }}</td>
                    <td class="px-5 py-2.5 text-center tabular-nums text-zinc-300">{{ row.wins }}</td>
                    <td class="px-5 py-2.5 text-right font-bold tabular-nums text-white">{{ row.points }}</td>
                </tr>
            </tbody>
        </table>
    </section>
</template>
