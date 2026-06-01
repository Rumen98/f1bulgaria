<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    season: Number,
    leaderboard: Array,
});
</script>

<template>
    <Head title="Класиране на прогнозите" />

    <PublicLayout>
        <h1 class="mb-2 text-2xl font-bold">Prediction League {{ season }}</h1>
        <p class="mb-6 text-sm text-gray-500">
            Точкуване: точен подиум, pole, най-бърза обиколка, брой DNF и safety car.
        </p>

        <div v-if="leaderboard.length === 0" class="rounded-lg border border-dashed border-gray-300 p-12 text-center text-gray-500">
            Все още няма точкувани прогнози.
        </div>

        <div v-else class="overflow-hidden rounded-lg border border-gray-200 bg-white">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-3">#</th>
                        <th class="px-4 py-3">Играч</th>
                        <th class="px-4 py-3 text-center">Прогнози</th>
                        <th class="px-4 py-3 text-right">Точки</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="row in leaderboard" :key="row.position" :class="{ 'bg-amber-50': row.position === 1 }">
                        <td class="px-4 py-3 font-bold text-gray-500">
                            <span v-if="row.position === 1">🏆</span>
                            {{ row.position }}
                        </td>
                        <td class="px-4 py-3 font-semibold">{{ row.name }}</td>
                        <td class="px-4 py-3 text-center text-gray-500">{{ row.predictions }}</td>
                        <td class="px-4 py-3 text-right font-bold">{{ row.points }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </PublicLayout>
</template>
