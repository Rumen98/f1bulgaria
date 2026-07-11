<script setup>
import EmptyState from '@/Components/UI/EmptyState.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    season: Number,
    teams: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Отбори Формула 2" />

    <PublicLayout>
        <Link :href="route('f2')" class="text-sm text-zinc-500 transition hover:text-zinc-300">← Формула 2</Link>
        <h1 class="mb-6 mt-1 font-display text-2xl font-black sm:text-3xl">Отбори F2 <span class="text-red-600">{{ season }}</span></h1>

        <EmptyState v-if="teams.length === 0">Няма данни за отбори.</EmptyState>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <Link
                v-for="t in teams"
                :key="t.slug"
                :href="route('f2.teams.show', t.slug)"
                class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-5 transition hover:border-zinc-600 hover:bg-zinc-900"
            >
                <div class="text-lg font-bold text-white">{{ t.name }}</div>
                <div class="mt-1 text-sm text-zinc-500">{{ t.drivers }} пилоти · <span class="font-semibold text-zinc-300 tabular-nums">{{ t.points }}</span> т.</div>
            </Link>
        </div>
    </PublicLayout>
</template>
