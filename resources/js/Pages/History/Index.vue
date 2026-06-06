<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const cards = [
    {
        route: 'history.world',
        emoji: '🌍',
        title: 'Световна история',
        desc: 'От Силвърстоун 1950 до ерата на Верстапен — ерите, които оформиха Формула 1.',
    },
    {
        route: 'history.bulgaria',
        emoji: '🇧🇬',
        title: 'F1 в България',
        desc: 'Как се ражда и расте българската страст към Формула 1 — от черно-белите екрани до днес.',
    },
];
</script>

<template>
    <Head title="Историята на Формула 1" />

    <PublicLayout>
        <header class="relative overflow-hidden rounded-2xl border border-zinc-800 bg-gradient-to-br from-red-950/40 via-zinc-950 to-black p-8 sm:p-12">
            <svg viewBox="0 0 400 200" preserveAspectRatio="xMidYMid slice" class="absolute inset-0 h-full w-full opacity-10">
                <g stroke="#e10600" stroke-width="3" stroke-linecap="round">
                    <line x1="-20" y1="50" x2="160" y2="50" />
                    <line x1="-20" y1="100" x2="240" y2="100" />
                    <line x1="-20" y1="150" x2="120" y2="150" />
                </g>
            </svg>
            <div class="relative">
                <h1 class="text-3xl font-black sm:text-5xl">Историята на Формула 1</h1>
                <p class="mt-4 max-w-2xl text-zinc-300 sm:text-lg">Над седем десетилетия скорост, легенди и драма — и мястото на България в тази страст.</p>
            </div>
        </header>

        <div class="mt-8 grid gap-5 sm:grid-cols-2">
            <Link
                v-for="c in cards"
                :key="c.route"
                :href="route(c.route)"
                class="group rounded-2xl border border-zinc-800 bg-zinc-900/60 p-8 transition duration-200 hover:border-red-600/50 hover:bg-zinc-900"
            >
                <div class="text-4xl">{{ c.emoji }}</div>
                <h2 class="mt-4 text-2xl font-black text-white">{{ c.title }}</h2>
                <p class="mt-2 text-zinc-400">{{ c.desc }}</p>
                <span class="mt-4 inline-block text-sm font-medium text-red-500 transition group-hover:text-red-400">Прочети →</span>
            </Link>
        </div>
    </PublicLayout>
</template>
