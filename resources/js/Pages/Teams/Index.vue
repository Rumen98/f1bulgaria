<script setup>
import TeamMonogram from '@/Components/Team/TeamMonogram.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    season: Number,
    teams: Array,
});
</script>

<template>
    <Head title="Отбори" />

    <PublicLayout>
        <h1 class="mb-6 text-2xl font-black sm:text-3xl">Отбори <span class="text-red-600">{{ season }}</span></h1>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Link
                v-for="team in teams"
                :key="team.slug"
                :href="route('teams.show', team.slug)"
                class="group relative overflow-hidden rounded-xl border border-zinc-800 bg-zinc-900/60 p-5 transition duration-200 hover:border-zinc-600 hover:bg-zinc-900"
            >
                <div class="absolute inset-y-0 left-0 w-1" :style="{ backgroundColor: team.color_hex ?? '#e10600' }" />
                <div class="flex items-center gap-4">
                    <div class="h-14 w-14">
                        <TeamMonogram :name="team.name" :color="team.color_hex ?? '#e10600'" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-lg font-bold text-white">{{ team.name }}</div>
                        <div class="text-sm text-zinc-500">{{ team.position }}-во място · {{ team.points }} т.</div>
                    </div>
                    <span class="text-zinc-600 transition group-hover:text-red-500">→</span>
                </div>
            </Link>
        </div>
    </PublicLayout>
</template>
