<script setup>
import CalendarSubscribe from '@/Components/Calendar/CalendarSubscribe.vue';
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
        <div class="mb-6 flex items-center justify-between gap-3">
            <h1 class="text-2xl font-black sm:text-3xl">Календар <span class="text-red-600">{{ season }}</span></h1>
            <div class="flex items-center gap-3">
                <CalendarSubscribe />
                <Link :href="route('standings')" class="hidden text-sm font-medium text-red-500 transition hover:text-red-400 sm:inline">
                    Класиране →
                </Link>
            </div>
        </div>

        <div v-if="races.length === 0" class="rounded-xl border border-dashed border-zinc-800 p-12 text-center text-zinc-500">
            Все още няма синхронизиран календар.
        </div>

        <div class="grid gap-3">
            <Link
                v-for="race in races"
                :key="race.id"
                :href="route('races.show', race.id)"
                class="group flex items-center justify-between rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 transition duration-200 hover:border-red-600/50 hover:bg-zinc-900"
            >
                <div class="flex items-center gap-4">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-zinc-800 text-sm font-bold tabular-nums text-zinc-300 transition group-hover:bg-red-600 group-hover:text-white">
                        {{ race.round }}
                    </span>
                    <div>
                        <div class="font-semibold text-white">
                            {{ race.name }}
                            <span v-if="race.has_sprint" class="ml-2 rounded bg-amber-500/15 px-1.5 py-0.5 text-xs font-medium text-amber-400">
                                Спринт
                            </span>
                        </div>
                        <div class="text-sm text-zinc-500">{{ race.circuit }}, {{ race.country }}</div>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-sm font-medium tabular-nums text-zinc-200">{{ race.race_at_sofia ?? 'TBC' }}</div>
                    <div v-if="race.finished" class="text-xs font-medium text-green-500">Завършено</div>
                    <div v-else class="text-xs text-zinc-500">Предстои</div>
                </div>
            </Link>
        </div>
    </PublicLayout>
</template>
