<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    predictions: Array,
});
</script>

<template>
    <Head title="Моите прогнози" />

    <PublicLayout>
        <h1 class="mb-6 text-2xl font-bold">Моите прогнози</h1>

        <div v-if="predictions.length === 0" class="rounded-lg border border-dashed border-gray-300 p-12 text-center text-gray-500">
            Все още нямаш прогнози. Отвори <Link :href="route('calendar')" class="text-red-600 hover:underline">календара</Link> и подай първата си.
        </div>

        <div class="grid gap-3">
            <Link
                v-for="prediction in predictions"
                :key="prediction.id"
                :href="route('races.show', prediction.race_id)"
                class="flex items-center justify-between rounded-lg border border-gray-200 bg-white p-4 hover:border-red-300"
            >
                <div>
                    <div class="font-semibold">Кръг {{ prediction.race?.round }} — {{ prediction.race?.name }}</div>
                    <div class="text-xs text-gray-500">
                        {{ prediction.locked ? 'Заключена' : 'Отворена за редакция' }}
                    </div>
                </div>
                <div class="text-right">
                    <div v-if="prediction.points !== null && prediction.points !== undefined" class="text-xl font-bold text-red-600">
                        {{ prediction.points }} т.
                    </div>
                    <div v-else class="text-xs text-gray-400">Все още не е точкувана</div>
                </div>
            </Link>
        </div>
    </PublicLayout>
</template>
