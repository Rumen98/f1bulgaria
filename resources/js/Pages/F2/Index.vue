<script setup>
import FlagIcon from '@/Components/FlagIcon.vue';
import SeasonSelect from '@/Components/UI/SeasonSelect.vue';
import TableShell from '@/Components/UI/TableShell.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { podiumClass } from '@/utils/racing';
import { hasRoute } from '@/utils/routes';
import { Link, router } from '@inertiajs/vue3';

const props = defineProps({
    season: Number,
    seasons: { type: Array, default: () => [] },
    standings: { type: Array, default: () => [] },
    champions: { type: Array, default: () => [] },
    bulgarianSpotlight: { type: Object, default: null },
});

const goToSeason = (e) => {
    router.visit(route('f2.season', e.target.value));
};
</script>

<template>

    <PublicLayout>
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="font-display text-2xl font-black sm:text-3xl">Формула 2 <span class="text-red-600">{{ season }}</span></h1>
                <p class="mt-1 text-sm text-zinc-500">Стъпалото преди Формула 1. Данните се поддържат ръчно.</p>
            </div>
            <SeasonSelect v-if="seasons.length" :seasons="seasons" :selected="season" @change="goToSeason" />
        </div>

        <!-- F2 под-навигация -->
        <div class="mb-6 flex flex-wrap gap-2">
            <Link v-if="hasRoute('f2.calendar')" :href="route('f2.calendar')" class="rounded-lg border border-zinc-800 bg-zinc-900/60 px-4 py-2 text-sm font-medium text-zinc-300 transition hover:border-red-600 hover:text-white">Календар</Link>
            <Link v-if="hasRoute('f2.drivers.index')" :href="route('f2.drivers.index')" class="rounded-lg border border-zinc-800 bg-zinc-900/60 px-4 py-2 text-sm font-medium text-zinc-300 transition hover:border-red-600 hover:text-white">Пилоти</Link>
            <Link v-if="hasRoute('f2.teams.index')" :href="route('f2.teams.index')" class="rounded-lg border border-zinc-800 bg-zinc-900/60 px-4 py-2 text-sm font-medium text-zinc-300 transition hover:border-red-600 hover:text-white">Отбори</Link>
        </div>

        <!-- Български състезател spotlight -->
        <Link
            v-if="bulgarianSpotlight && hasRoute('f2.drivers.show')"
            :href="route('f2.drivers.show', bulgarianSpotlight.slug)"
            class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-emerald-500/40 bg-gradient-to-r from-emerald-500/10 to-zinc-950 p-5 transition hover:border-emerald-500"
        >
            <div>
                <div class="text-xs font-bold uppercase tracking-[0.2em] text-emerald-400">Български състезател в F2 🇧🇬</div>
                <div class="mt-1 text-xl font-black text-white"><FlagIcon :code="bulgarianSpotlight.flag" class="mr-1" />{{ bulgarianSpotlight.name }}</div>
                <div class="text-sm text-zinc-400">{{ bulgarianSpotlight.team }}</div>
            </div>
            <div class="text-right">
                <div class="font-display text-2xl font-black tabular-nums text-white">{{ bulgarianSpotlight.position ? 'P' + bulgarianSpotlight.position : '—' }}</div>
                <div class="text-sm tabular-nums text-zinc-400">{{ bulgarianSpotlight.points }} т.</div>
            </div>
        </Link>

        <!-- Класиране -->
        <TableShell>
            <table class="w-full whitespace-nowrap text-sm">
                <thead class="bg-zinc-900/80 text-xs uppercase tracking-wide text-zinc-500">
                    <tr>
                        <th scope="col" class="px-4 py-2.5 text-left">#</th>
                        <th scope="col" class="px-4 py-2.5 text-left">Пилот</th>
                        <th scope="col" class="px-4 py-2.5 text-left">Отбор</th>
                        <th scope="col" class="px-4 py-2.5 text-right">Точки</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800/60">
                    <tr v-for="row in standings" :key="row.slug" class="bg-zinc-900/40">
                        <td class="px-4 py-2.5 font-bold tabular-nums" :class="podiumClass(row.position) || 'text-zinc-500'">{{ row.position ?? '—' }}</td>
                        <td class="px-4 py-2.5 font-semibold text-white">
                            <FlagIcon :code="row.flag" class="mr-1" />{{ row.name }}
                            <span v-if="row.is_champion" title="Шампион"><span aria-hidden="true">🏆</span><span class="sr-only">Шампион</span></span>
                        </td>
                        <td class="px-4 py-2.5 text-zinc-400">{{ row.team ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums text-white">{{ row.points }}</td>
                    </tr>
                </tbody>
            </table>
            <div v-if="standings.length === 0" class="p-8 text-center text-zinc-500">Няма данни за сезона.</div>
        </TableShell>

        <!-- Галерия на шампионите -->
        <section v-if="champions.length" class="mt-10">
            <h2 class="mb-4 font-display text-lg font-bold text-white">Шампиони по сезони</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="c in champions"
                    :key="c.year"
                    :href="route('f2.season', c.year)"
                    class="flex items-center gap-3 rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 transition hover:border-amber-500/40"
                >
                    <span class="font-display text-2xl font-black tabular-nums text-amber-300">{{ c.year }}</span>
                    <div>
                        <div class="font-semibold text-white"><FlagIcon :code="c.flag" class="mr-1" />{{ c.name }}</div>
                        <div class="text-xs text-zinc-500">{{ c.team }}</div>
                    </div>
                </Link>
            </div>
        </section>
    </PublicLayout>
</template>
