<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import StatTile from '@/Components/UI/StatTile.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    profile: Object,
    stats: Object,
    season: Number,
});
</script>

<template>
    <Head :title="profile.name" />

    <PublicLayout>
        <div class="grid gap-6 md:grid-cols-3">
            <div class="md:col-span-1">
                <div class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-6 text-center">
                    <div class="mx-auto mb-3 flex h-20 w-20 items-center justify-center rounded-full bg-gradient-to-br from-red-600 to-red-800 text-2xl font-black text-white">
                        {{ profile.name.charAt(0).toUpperCase() }}
                    </div>
                    <h1 class="font-display text-2xl font-black text-white sm:text-3xl">{{ profile.name }}</h1>
                    <p v-if="profile.bio" class="mt-2 text-sm text-zinc-400">{{ profile.bio }}</p>

                    <dl class="mt-4 space-y-1 text-sm text-zinc-400">
                        <div v-if="profile.favorite_driver">
                            <dt class="inline font-medium text-zinc-300">Любим пилот:</dt>
                            {{ profile.favorite_driver.full_name }}
                        </div>
                        <div v-if="profile.favorite_constructor">
                            <dt class="inline font-medium text-zinc-300">Любим отбор:</dt>
                            {{ profile.favorite_constructor.name }}
                        </div>
                    </dl>
                </div>
            </div>

            <div class="space-y-6 md:col-span-2">
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <StatTile
                        v-for="stat in [
                            { label: `точки ${season}`, value: stats.points, accent: true },
                            { label: 'прогнози', value: stats.predictions },
                            { label: 'най-добра', value: stats.best },
                            { label: 'средно', value: stats.average },
                        ]"
                        :key="stat.label"
                        :label="stat.label"
                        :value-class="stat.accent ? 'text-red-600' : 'text-white'"
                    >
                        {{ stat.value }}
                    </StatTile>
                </div>

                <div class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-6">
                    <h2 class="mb-4 font-display text-lg font-bold text-white">Значки</h2>
                    <div v-if="profile.badges.length === 0" class="text-sm text-zinc-500">
                        Все още няма спечелени значки.
                    </div>
                    <div v-else class="flex flex-wrap gap-3">
                        <div
                            v-for="badge in profile.badges"
                            :key="badge.slug"
                            class="flex items-center gap-2 rounded-full border border-amber-500/30 bg-amber-500/10 px-3 py-1.5 text-sm"
                            :title="badge.description"
                        >
                            <span aria-hidden="true">🏅</span>
                            <span class="font-medium text-amber-300">{{ badge.name }}</span>
                            <span class="sr-only">{{ badge.description }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </PublicLayout>
</template>
