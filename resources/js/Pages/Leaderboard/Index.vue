<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    season: Number,
    leaderboard: Array,
});

const podium = (pos) => ({
    1: 'border-l-2 border-amber-400 bg-gradient-to-r from-amber-500/10 to-transparent',
    2: 'border-l-2 border-zinc-300 bg-gradient-to-r from-zinc-400/10 to-transparent',
    3: 'border-l-2 border-orange-700 bg-gradient-to-r from-orange-800/10 to-transparent',
})[pos] ?? 'bg-zinc-900/40';
</script>

<template>
    <Head title="Класиране на прогнозите">
        <meta head-key="description" name="description" content="Класиране на играта с прогнози — познай резултатите от Формула 1 и събирай точки." />
    </Head>

    <PublicLayout>
        <h1 class="mb-2 text-2xl font-black sm:text-3xl">Prediction League <span class="text-red-600">{{ season }}</span></h1>
        <p class="mb-6 text-sm text-zinc-500">
            Точкуване: точен подиум, pole, най-бърза обиколка, брой DNF и safety car.
        </p>

        <div v-if="leaderboard.length === 0" class="rounded-xl border border-dashed border-zinc-800 p-12 text-center text-zinc-500">
            Все още няма точкувани прогнози.
        </div>

        <div v-else class="overflow-hidden rounded-xl border border-zinc-800">
            <table class="w-full text-sm">
                <thead class="bg-zinc-900 text-left text-xs uppercase tracking-wide text-zinc-500">
                    <tr>
                        <th class="px-4 py-3 w-12">#</th>
                        <th class="px-4 py-3">Играч</th>
                        <th class="px-4 py-3 text-center">Прогнози</th>
                        <th class="px-4 py-3 text-right">Точки</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800">
                    <tr
                        v-for="row in leaderboard"
                        :key="row.position"
                        class="transition duration-200 hover:bg-zinc-800/50"
                        :class="podium(row.position)"
                    >
                        <td class="px-4 py-3 font-bold tabular-nums text-zinc-400">
                            <span v-if="row.position === 1">🏆</span>
                            <span v-else>{{ row.position }}</span>
                        </td>
                        <td class="px-4 py-3 font-semibold text-white">{{ row.name }}</td>
                        <td class="px-4 py-3 text-center tabular-nums text-zinc-400">{{ row.predictions }}</td>
                        <td class="px-4 py-3 text-right font-bold tabular-nums text-white">{{ row.points }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </PublicLayout>
</template>
