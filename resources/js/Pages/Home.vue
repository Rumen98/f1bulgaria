<script setup>
import HeroSection from '@/Components/Hero/HeroSection.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    hero: { type: Object, required: true },
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

        <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
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
    </PublicLayout>
</template>
