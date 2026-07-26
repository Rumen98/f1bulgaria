<script setup>
import EmptyState from '@/Components/UI/EmptyState.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Link } from '@inertiajs/vue3';

defineProps({
    predictions: Array,
});
</script>

<template>

    <PublicLayout>
        <h1 class="mb-6 font-display text-2xl font-black sm:text-3xl">Моите прогнози</h1>

        <EmptyState v-if="predictions.length === 0">
            Все още нямаш прогнози. Отвори <Link :href="route('calendar')" class="text-red-500 hover:text-red-400">календара</Link> и подай първата си.
        </EmptyState>

        <div class="grid gap-3">
            <Link
                v-for="prediction in predictions"
                :key="prediction.id"
                :href="route('races.show', prediction.race_id)"
                class="flex items-center justify-between rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 transition duration-200 hover:border-red-600/50 hover:bg-zinc-900"
            >
                <div>
                    <div class="font-semibold text-white">Кръг {{ prediction.race?.round }} — {{ prediction.race?.name }}</div>
                    <div class="text-xs text-zinc-500">
                        {{ prediction.locked ? 'Заключена' : 'Отворена за редакция' }}
                    </div>
                </div>
                <div class="text-right">
                    <div v-if="prediction.points !== null && prediction.points !== undefined" class="font-display text-xl font-bold tabular-nums text-red-600">
                        {{ prediction.points }} т.
                    </div>
                    <div v-else class="text-xs text-zinc-500">Все още не е точкувана</div>
                </div>
            </Link>
        </div>
    </PublicLayout>
</template>
