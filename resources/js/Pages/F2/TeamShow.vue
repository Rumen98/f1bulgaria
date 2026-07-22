<script setup>
import FlagIcon from '@/Components/FlagIcon.vue';
import StatTile from '@/Components/UI/StatTile.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    team: { type: Object, required: true },
    stats: { type: Object, required: true },
    currentDrivers: { type: Array, default: () => [] },
    alumni: { type: Array, default: () => [] },
});

const statCards = [
    { key: 'seasons', label: 'Сезони' },
    { key: 'wins', label: 'Победи' },
    { key: 'podiums', label: 'Подиуми' },
    { key: 'championships', label: 'Титли' },
];
</script>

<template>
    <Head :title="team.name" />

    <PublicLayout>
        <Link :href="route('f2.teams.index')" class="text-sm text-zinc-500 transition hover:text-zinc-300">← Отбори F2</Link>
        <h1 class="mb-6 mt-1 font-display text-3xl font-black sm:text-4xl">{{ team.name }}</h1>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <StatTile v-for="c in statCards" :key="c.key" :label="c.label">{{ stats[c.key] }}</StatTile>
        </div>

        <section v-if="currentDrivers.length" class="mt-8">
            <h2 class="mb-3 font-display text-lg font-bold text-white">Настоящи пилоти</h2>
            <div class="flex flex-wrap gap-2">
                <Link
                    v-for="d in currentDrivers"
                    :key="d.slug"
                    :href="route('f2.drivers.show', d.slug)"
                    class="rounded-full border px-4 py-2 text-sm font-medium transition"
                    :class="d.is_bulgarian ? 'border-emerald-500/50 text-emerald-300 hover:bg-emerald-500/10' : 'border-zinc-700 text-zinc-200 hover:border-red-600 hover:text-white'"
                >
                    <FlagIcon :code="d.flag" class="mr-1" />{{ d.name }}
                </Link>
            </div>
        </section>

        <section v-if="alumni.length" class="mt-8">
            <h2 class="mb-3 font-display text-lg font-bold text-white">Пилоти през годините</h2>
            <div class="grid gap-2 sm:grid-cols-2">
                <Link
                    v-for="a in alumni"
                    :key="a.slug"
                    :href="route('f2.drivers.show', a.slug)"
                    class="flex items-center justify-between rounded-lg border border-zinc-800 bg-zinc-900/60 px-4 py-2.5 text-sm transition hover:border-zinc-600"
                >
                    <span class="text-zinc-200"><FlagIcon :code="a.flag" class="mr-1" />{{ a.name }}</span>
                    <span class="tabular-nums text-zinc-500">{{ a.years.join(', ') }}</span>
                </Link>
            </div>
        </section>
    </PublicLayout>
</template>
