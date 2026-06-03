<script setup>
import DriverRecentResults from '@/Components/Driver/DriverRecentResults.vue';
import DriverStatsGrid from '@/Components/Driver/DriverStatsGrid.vue';
import HeadToHeadBars from '@/Components/Driver/HeadToHeadBars.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    driver: Object,
    season: Number,
    seasonStats: Object,
    allTimeStats: Object,
    headToHead: Object,
    recentResults: Array,
});
</script>

<template>
    <Head :title="driver.name" />

    <PublicLayout>
        <Link :href="route('drivers.index')" class="text-sm text-zinc-500 transition hover:text-zinc-300">← Всички пилоти</Link>

        <!-- Hero -->
        <section
            class="relative mt-3 overflow-hidden rounded-2xl border border-zinc-800 p-6 sm:p-8"
            :style="{ background: `linear-gradient(110deg, ${driver.color_hex}33, #0a0a0a 60%)` }"
        >
            <div class="flex items-center gap-5 sm:gap-8">
                <div
                    class="select-none text-6xl font-black leading-none tabular-nums sm:text-8xl"
                    :style="{ color: driver.color_hex, textShadow: '0 2px 0 rgba(0,0,0,0.4), 0 0 24px ' + driver.color_hex + '55' }"
                >
                    {{ driver.number ?? '' }}
                </div>
                <div>
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
    </PublicLayout>
</template>
