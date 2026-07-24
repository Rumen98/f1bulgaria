<script setup>
import FlagIcon from '@/Components/FlagIcon.vue';
import StatTile from '@/Components/UI/StatTile.vue';
import TableShell from '@/Components/UI/TableShell.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { hasRoute } from '@/utils/routes';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    driver: { type: Object, required: true },
    stats: { type: Object, required: true },
    seasons: { type: Array, default: () => [] },
    recentResults: { type: Array, default: () => [] },
});

const statCards = [
    { key: 'starts', label: 'Старта' },
    { key: 'wins', label: 'Победи' },
    { key: 'podiums', label: 'Подиуми' },
    { key: 'poles', label: 'Pole' },
    { key: 'championships', label: 'Титли' },
    { key: 'seasons', label: 'Сезони' },
];
</script>

<template>
    <Head :title="driver.name" />

    <PublicLayout>
        <Link :href="route('f2.drivers.index')" class="text-sm text-zinc-500 transition hover:text-zinc-300">← Пилоти F2</Link>

        <!-- Hero -->
        <section
            class="mt-3 rounded-2xl border p-6 sm:p-8"
            :class="driver.is_bulgarian ? 'border-emerald-500/40' : 'border-zinc-800'"
            :style="driver.is_bulgarian ? 'background: linear-gradient(110deg, rgba(16,185,129,0.15), #0a0a0a 60%)' : ''"
        >
            <div v-if="driver.is_bulgarian" class="mb-2 text-xs font-bold uppercase tracking-[0.2em] text-emerald-400">Български състезател в F2 🇧🇬</div>
            <h1 class="font-display text-3xl font-black sm:text-4xl"><FlagIcon :code="driver.flag" class="mr-1" />{{ driver.name }}</h1>
            <div class="mt-1 flex flex-wrap items-center gap-3 text-zinc-400">
                <span v-if="driver.car_number">#{{ driver.car_number }}</span>
                <span v-if="driver.current_team">· {{ driver.current_team }}</span>
            </div>
            <Link
                v-if="driver.is_bulgarian && hasRoute('tsolov')"
                :href="route('tsolov')"
                class="mt-3 inline-block text-sm font-medium text-emerald-400 transition hover:text-emerald-300"
            >
                Цялата история на Цолов →
            </Link>
        </section>

        <!-- Career stats -->
        <div class="mt-6 grid grid-cols-3 gap-3 sm:grid-cols-6">
            <StatTile v-for="c in statCards" :key="c.key" :label="c.label">{{ stats[c.key] }}</StatTile>
        </div>

        <!-- Season by season -->
        <section class="mt-8">
            <h2 class="mb-3 font-display text-lg font-bold text-white">Сезон по сезон</h2>
            <TableShell>
                <table class="w-full whitespace-nowrap text-sm">
                    <thead class="bg-zinc-900/80 text-xs uppercase tracking-wide text-zinc-500">
                        <tr><th scope="col" class="px-4 py-2.5 text-left">Сезон</th><th scope="col" class="px-4 py-2.5 text-left">Отбор</th><th scope="col" class="px-4 py-2.5 text-right">Поз.</th><th scope="col" class="px-4 py-2.5 text-right">Точки</th></tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-800/60">
                        <tr v-for="s in seasons" :key="s.year" class="bg-zinc-900/40">
                            <td class="px-4 py-2.5 font-bold tabular-nums text-white">
                                <Link :href="route('f2.season', s.year)" class="transition hover:text-red-400">{{ s.year }}</Link>
                                <span v-if="s.is_champion" title="Шампион"><span aria-hidden="true">🏆</span><span class="sr-only">Шампион</span></span>
                            </td>
                            <td class="px-4 py-2.5 text-zinc-300">{{ s.team ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-zinc-300">{{ s.position ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-right font-bold tabular-nums text-white">{{ s.points }}</td>
                        </tr>
                    </tbody>
                </table>
            </TableShell>
        </section>

        <!-- Recent results -->
        <section v-if="recentResults.length" class="mt-8">
            <h2 class="mb-3 font-display text-lg font-bold text-white">Последни резултати</h2>
            <div class="space-y-2">
                <Link
                    v-for="(r, i) in recentResults"
                    :key="i"
                    :href="route('f2.race', [r.race_slug, r.session_type])"
                    class="flex items-center justify-between rounded-lg border border-zinc-800 bg-zinc-900/60 px-4 py-2.5 text-sm transition hover:border-zinc-600"
                >
                    <span class="text-zinc-300">{{ r.year }} · {{ r.location }} <span class="text-zinc-500">— {{ r.session }}</span></span>
                    <span class="font-bold tabular-nums text-white">{{ r.position ? 'P' + r.position : r.status }} <span class="text-zinc-500">{{ r.points ? '· ' + r.points : '' }}</span></span>
                </Link>
            </div>
        </section>
    </PublicLayout>
</template>
