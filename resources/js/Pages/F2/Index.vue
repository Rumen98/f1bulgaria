<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    season: Number,
    seasons: { type: Array, default: () => [] },
    standings: { type: Array, default: () => [] },
    champions: { type: Array, default: () => [] },
});

const goToSeason = (e) => {
    router.visit(route('f2.season', e.target.value));
};

const posClass = (p) => ({ 1: 'text-amber-300', 2: 'text-zinc-300', 3: 'text-orange-400' })[p] ?? 'text-zinc-500';
</script>

<template>
    <Head title="Формула 2" />

    <PublicLayout>
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-black sm:text-3xl">Формула 2 <span class="text-red-600">{{ season }}</span></h1>
                <p class="mt-1 text-sm text-zinc-500">Стъпалото преди Формула 1. Данните се поддържат ръчно.</p>
            </div>
            <select
                v-if="seasons.length"
                :value="season"
                class="rounded-lg border border-zinc-800 bg-zinc-900 px-3 py-2 text-sm text-white focus:border-red-600 focus:outline-none"
                @change="goToSeason"
            >
                <option v-for="y in seasons" :key="y" :value="y">{{ y }}</option>
            </select>
        </div>

        <!-- Класиране -->
        <div class="overflow-hidden rounded-xl border border-zinc-800">
            <table class="w-full text-sm">
                <thead class="bg-zinc-900/80 text-xs uppercase tracking-wide text-zinc-500">
                    <tr>
                        <th class="px-4 py-2 text-left">#</th>
                        <th class="px-4 py-2 text-left">Пилот</th>
                        <th class="px-4 py-2 text-left">Отбор</th>
                        <th class="px-4 py-2 text-right">Точки</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800/60">
                    <tr v-for="row in standings" :key="row.slug" class="bg-zinc-900/40">
                        <td class="px-4 py-2.5 font-bold tabular-nums" :class="posClass(row.position)">{{ row.position ?? '—' }}</td>
                        <td class="px-4 py-2.5 font-semibold text-white">
                            {{ row.flag }} {{ row.name }}
                            <span v-if="row.is_champion" title="Шампион">🏆</span>
                        </td>
                        <td class="px-4 py-2.5 text-zinc-400">{{ row.team ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums text-white">{{ row.points }}</td>
                    </tr>
                </tbody>
            </table>
            <div v-if="standings.length === 0" class="p-8 text-center text-zinc-500">Няма данни за сезона.</div>
        </div>

        <!-- Галерия на шампионите -->
        <section v-if="champions.length" class="mt-10">
            <h2 class="mb-4 text-lg font-bold text-white">Шампиони по сезони</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="c in champions"
                    :key="c.year"
                    :href="route('f2.season', c.year)"
                    class="flex items-center gap-3 rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 transition hover:border-amber-500/40"
                >
                    <span class="text-2xl font-black tabular-nums text-amber-300">{{ c.year }}</span>
                    <div>
                        <div class="font-semibold text-white">{{ c.flag }} {{ c.name }}</div>
                        <div class="text-xs text-zinc-500">{{ c.team }}</div>
                    </div>
                </Link>
            </div>
        </section>
    </PublicLayout>
</template>
