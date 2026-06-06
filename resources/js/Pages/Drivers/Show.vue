<script setup>
import CareerTimeline from '@/Components/Driver/CareerTimeline.vue';
import DriverAchievements from '@/Components/Driver/DriverAchievements.vue';
import DriverRecentResults from '@/Components/Driver/DriverRecentResults.vue';
import DriverStatsGrid from '@/Components/Driver/DriverStatsGrid.vue';
import HeadToHeadBars from '@/Components/Driver/HeadToHeadBars.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    driver: Object,
    season: Number,
    seasons: { type: Array, default: () => [] },
    selectedSeason: { type: Number, default: null },
    isHistorical: { type: Boolean, default: false },
    seasonStats: Object,
    allTimeStats: Object,
    achievements: Object,
    circuitWins: { type: Array, default: () => [] },
    careerTimeline: { type: Array, default: () => [] },
    headToHead: Object,
    recentResults: Array,
});

const goToSeason = (e) => {
    router.visit(route('drivers.show', props.driver.slug) + `?season=${e.target.value}`, { preserveScroll: true });
};
</script>

<template>
    <Head :title="driver.name" />

    <PublicLayout>
        <div class="flex items-center justify-between gap-3">
            <Link :href="route('drivers.index')" class="text-sm text-zinc-500 transition hover:text-zinc-300">← Всички пилоти</Link>
            <select
                v-if="seasons.length > 1"
                :value="selectedSeason"
                class="rounded-lg border border-zinc-800 bg-zinc-900 px-3 py-1.5 text-sm text-white focus:border-red-600 focus:outline-none"
                @change="goToSeason"
            >
                <option v-for="y in seasons" :key="y" :value="y">Сезон {{ y }}</option>
            </select>
        </div>

        <!-- Hero -->
        <section
            class="relative mt-3 overflow-hidden rounded-2xl border border-zinc-800 p-6 sm:p-8"
            :style="{ background: `linear-gradient(110deg, ${driver.color_hex}33, #0a0a0a 60%)` }"
        >
            <div class="flex items-center gap-5 sm:gap-8">
                <!-- Снимка от Wikimedia (ако има), иначе голям номер -->
                <img
                    v-if="driver.photo"
                    :src="driver.photo"
                    :alt="driver.name"
                    loading="lazy"
                    referrerpolicy="no-referrer"
                    class="h-28 w-28 flex-shrink-0 rounded-2xl object-cover object-top shadow-xl ring-2 sm:h-40 sm:w-40"
                    :style="{ '--tw-ring-color': driver.color_hex, boxShadow: '0 0 40px ' + driver.color_hex + '40' }"
                />
                <div
                    v-else
                    class="select-none text-6xl font-black leading-none tabular-nums sm:text-8xl"
                    :style="{ color: driver.color_hex, textShadow: '0 2px 0 rgba(0,0,0,0.4), 0 0 24px ' + driver.color_hex + '55' }"
                >
                    {{ driver.number ?? '' }}
                </div>
                <div>
                    <span v-if="isHistorical" class="mb-2 inline-block rounded-full bg-amber-500/20 px-2.5 py-0.5 text-xs font-bold uppercase tracking-wide text-amber-400">
                        Легенда · последен сезон {{ season }}
                    </span>
                    <h1 class="text-3xl font-black sm:text-4xl">
                        <span v-if="driver.flag">{{ driver.flag }} </span>{{ driver.name }}
                    </h1>
                    <div class="mt-2 flex flex-wrap items-center gap-3 text-zinc-300">
                        <Link
                            v-if="driver.team_slug"
                            :href="route('teams.show', driver.team_slug)"
                            class="inline-flex items-center gap-2 transition hover:text-white"
                        >
                            <span class="h-3 w-3 rounded-full" :style="{ backgroundColor: driver.color_hex }" />
                            {{ driver.team }}
                        </Link>
                        <span v-if="seasonStats.position" class="text-zinc-600">·</span>
                        <span v-if="seasonStats.position" class="tabular-nums">P{{ seasonStats.position }} · {{ seasonStats.points }} т.</span>
                    </div>
                </div>
            </div>
        </section>

        <div class="mt-6">
            <DriverStatsGrid :season="seasonStats" :all-time="allTimeStats" :season-year="season" />
        </div>

        <div class="mt-8 grid gap-8 lg:grid-cols-2">
            <DriverRecentResults :results="recentResults" />
            <HeadToHeadBars :h2h="headToHead" :driver-name="driver.name" :color="driver.color_hex" />
        </div>

        <div class="mt-10">
            <DriverAchievements :achievements="achievements" :circuit-wins="circuitWins" />
        </div>

        <div v-if="careerTimeline.length" class="mt-10">
            <CareerTimeline :timeline="careerTimeline" :highlight-year="selectedSeason" />
        </div>
    </PublicLayout>
</template>
