<script setup>
import HeroSection from '@/Components/Hero/HeroSection.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    hero: { type: Object, required: true },
});
</script>

<template>
    <Head title="F1 България" />

    <PublicLayout>
        <HeroSection :hero="hero" />

        <div class="mt-8 grid gap-4 sm:grid-cols-3">
            <Link :href="route('calendar')" class="rounded-lg border border-gray-200 bg-white p-5 transition hover:border-red-300">
                <div class="font-semibold">Календар</div>
                <div class="text-sm text-gray-500">Всички състезания за сезона</div>
            </Link>
            <Link :href="route('standings')" class="rounded-lg border border-gray-200 bg-white p-5 transition hover:border-red-300">
                <div class="font-semibold">Класиране</div>
                <div class="text-sm text-gray-500">Пилоти и конструктори</div>
            </Link>
            <Link :href="route('leaderboard')" class="rounded-lg border border-gray-200 bg-white p-5 transition hover:border-red-300">
                <div class="font-semibold">Prediction League</div>
                <div class="text-sm text-gray-500">Познай и събирай точки</div>
            </Link>
        </div>
    </PublicLayout>
</template>
