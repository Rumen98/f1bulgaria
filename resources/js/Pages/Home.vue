<script setup>
import HeroSection from '@/Components/Hero/HeroSection.vue';
import ThisDayWidget from '@/Components/Homepage/ThisDayWidget.vue';
import NewsCard from '@/Components/News/NewsCard.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    hero: { type: Object, required: true },
    thisDay: { type: Array, default: () => [] },
    topNews: { type: Array, default: () => [] },
});

const allLinks = [
    { label: 'Календар', desc: 'Всички състезания за сезона', route: 'calendar' },
    { label: 'Класиране', desc: 'Пилоти и конструктори', route: 'standings' },
    { label: 'Отбори', desc: '10-те отбора с branding и статистика', route: 'teams.index' },
    { label: 'Пилоти', desc: 'Профили, статистика, head-to-head', route: 'drivers.index' },
    { label: 'Писти', desc: 'История и all-time класирания', route: 'circuits.index' },
    { label: 'Prediction League', desc: 'Познай и събирай точки', route: 'leaderboard' },
];

const has = (name) => {
    try {
        return route().has(name);
    } catch (e) {
        return false;
    }
};
const links = computed(() => allLinks.filter((l) => has(l.route)));
</script>

<template>
    <Head title="F1 България" />

    <PublicLayout>
        <HeroSection :hero="hero" />

        <!-- На този ден във F1 -->
        <ThisDayWidget :events="thisDay" />

        <!-- Топ новини -->
        <section v-if="topNews.length" class="mt-10">
            <div class="mb-4 flex items-baseline justify-between">
                <h2 class="text-xl font-black sm:text-2xl">Топ новини</h2>
                <Link v-if="has('news.index')" :href="route('news.index')" class="text-sm font-medium text-red-500 transition hover:text-red-400">
                    Всички новини →
                </Link>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <NewsCard v-for="(item, i) in topNews" :key="i" :item="item" />
            </div>
        </section>

        <!-- Разделите на платформата -->
        <section class="mt-10">
            <h2 class="mb-4 text-xl font-black sm:text-2xl">Разгледай</h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="link in links"
                    :key="link.route"
                    :href="route(link.route)"
                    class="group rounded-xl border border-zinc-800 bg-zinc-900/60 p-5 transition duration-200 hover:border-red-600/50 hover:bg-zinc-900"
                >
                    <div class="font-semibold text-white transition group-hover:text-red-400">{{ link.label }}</div>
                    <div class="mt-1 text-sm text-zinc-500">{{ link.desc }}</div>
                </Link>
            </div>
        </section>
    </PublicLayout>
</template>
