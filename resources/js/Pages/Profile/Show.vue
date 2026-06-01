<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
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
        <div class="grid gap-8 md:grid-cols-3">
            <!-- Профил -->
            <div class="md:col-span-1">
                <div class="rounded-lg border border-gray-200 bg-white p-6 text-center">
                    <div class="mx-auto mb-3 flex h-20 w-20 items-center justify-center rounded-full bg-gray-900 text-2xl font-bold text-white">
                        {{ profile.name.charAt(0).toUpperCase() }}
                    </div>
                    <h1 class="text-xl font-bold">{{ profile.name }}</h1>
                    <p v-if="profile.bio" class="mt-2 text-sm text-gray-600">{{ profile.bio }}</p>

                    <dl class="mt-4 space-y-1 text-sm text-gray-600">
                        <div v-if="profile.favorite_driver">
                            <dt class="inline font-medium">Любим пилот:</dt>
                            {{ profile.favorite_driver.full_name }}
                        </div>
                        <div v-if="profile.favorite_constructor">
                            <dt class="inline font-medium">Любим отбор:</dt>
                            {{ profile.favorite_constructor.name }}
                        </div>
                    </dl>
                </div>
            </div>

            <!-- Статистика + значки -->
            <div class="space-y-6 md:col-span-2">
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div class="rounded-lg border border-gray-200 bg-white p-4 text-center">
                        <div class="text-2xl font-bold text-red-600">{{ stats.points }}</div>
                        <div class="text-xs uppercase text-gray-500">точки {{ season }}</div>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white p-4 text-center">
                        <div class="text-2xl font-bold">{{ stats.predictions }}</div>
                        <div class="text-xs uppercase text-gray-500">прогнози</div>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white p-4 text-center">
                        <div class="text-2xl font-bold">{{ stats.best }}</div>
                        <div class="text-xs uppercase text-gray-500">най-добра</div>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white p-4 text-center">
                        <div class="text-2xl font-bold">{{ stats.average }}</div>
                        <div class="text-xs uppercase text-gray-500">средно</div>
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white p-6">
                    <h2 class="mb-4 font-semibold">Значки</h2>
                    <div v-if="profile.badges.length === 0" class="text-sm text-gray-500">
                        Все още няма спечелени значки.
                    </div>
                    <div v-else class="flex flex-wrap gap-3">
                        <div
                            v-for="badge in profile.badges"
                            :key="badge.slug"
                            class="flex items-center gap-2 rounded-full border border-amber-200 bg-amber-50 px-3 py-1.5 text-sm"
                            :title="badge.description"
                        >
                            <span>🏅</span>
                            <span class="font-medium text-amber-900">{{ badge.name }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </PublicLayout>
</template>
