<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    season: Number,
    races: Array,
});
</script>

<template>
    <Head title="Календар" />

    <PublicLayout>
        <div class="mb-6 flex items-baseline justify-between">
            <h1 class="text-2xl font-bold">Календар {{ season }}</h1>
            <Link :href="route('standings')" class="text-sm font-medium text-red-600 hover:underline">
                Виж класирането →
            </Link>
        </div>

        <div v-if="races.length === 0" class="rounded-lg border border-dashed border-gray-300 p-12 text-center text-gray-500">
            Все още няма синхронизиран календар. Стартирай <code>php artisan f1:sync-season</code>.
        </div>

        <div class="grid gap-3">
            <Link
                v-for="race in races"
                :key="race.id"
                :href="route('races.show', race.id)"
                class="flex items-center justify-between rounded-lg border border-gray-200 bg-white p-4 transition hover:border-red-300 hover:shadow-sm"
            >
                <div class="flex items-center gap-4">
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-gray-900 text-sm font-bold text-white">
                        {{ race.round }}
                    </span>
                    <div>
                        <div class="font-semibold">
                            {{ race.name }}
                            <span v-if="race.has_sprint" class="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-800">
                                Спринт
                            </span>
                        </div>
                        <div class="text-sm text-gray-500">{{ race.circuit }}, {{ race.country }}</div>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-sm font-medium text-gray-900">{{ race.race_at_sofia ?? 'TBC' }}</div>
                    <div v-if="race.finished" class="text-xs font-medium text-green-600">Завършено</div>
                    <div v-else class="text-xs text-gray-400">Предстои</div>
                </div>
            </Link>
        </div>
    </PublicLayout>
</template>
