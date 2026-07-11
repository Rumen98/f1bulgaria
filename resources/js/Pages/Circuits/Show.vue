<script setup>
import AllTimeLeaderboard from '@/Components/Circuit/AllTimeLeaderboard.vue';
import CircuitHero from '@/Components/Circuit/CircuitHero.vue';
import CircuitRecords from '@/Components/Circuit/CircuitRecords.vue';
import CircuitTechnical from '@/Components/Circuit/CircuitTechnical.vue';
import RecentWinners from '@/Components/Circuit/RecentWinners.vue';
import TableShell from '@/Components/UI/TableShell.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { NEUTRAL_DOT_COLOR } from '@/utils/racing';
import { hasRoute } from '@/utils/routes';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    circuit: Object,
    technical: { type: Object, default: null },
    standings: Array,
    lastWinners: Array,
    records: Object,
    lastRace: Object,
    f2Winners: { type: Array, default: () => [] },
});
</script>

<template>
    <Head :title="circuit.name" />

    <PublicLayout>
        <Link :href="route('circuits.index')" class="text-sm text-zinc-500 transition hover:text-zinc-300">← Всички писти</Link>

        <div class="mt-3">
            <CircuitHero :circuit="circuit" :technical="technical" />
        </div>

        <!-- Технически данни -->
        <div v-if="technical" class="mt-8">
            <CircuitTechnical :tech="technical" />
        </div>

        <!-- Сигнатурата: all-time класиране -->
        <div class="mt-8">
            <AllTimeLeaderboard :standings="standings" />
        </div>

        <div class="mt-8">
            <CircuitRecords :records="records" />
        </div>

        <!-- Формула 2 на тази писта -->
        <section v-if="f2Winners.length" class="mt-8">
            <h2 class="mb-3 font-display text-lg font-bold text-white">Формула 2 на тази писта</h2>
            <TableShell>
                <table class="w-full text-sm">
                    <thead class="bg-zinc-900/80 text-xs uppercase tracking-wide text-zinc-500">
                        <tr><th scope="col" class="px-4 py-2.5 text-left">Сезон</th><th scope="col" class="px-4 py-2.5 text-left">Победител (главно)</th><th scope="col" class="px-4 py-2.5 text-right"><span class="sr-only">Действия</span></th></tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-800/60">
                        <tr v-for="w in f2Winners" :key="w.year" class="bg-zinc-900/40">
                            <td class="px-4 py-2.5 font-bold tabular-nums text-white">{{ w.year }}</td>
                            <td class="px-4 py-2.5 text-zinc-200">
                                <Link v-if="hasRoute('f2.drivers.show')" :href="route('f2.drivers.show', w.slug)" class="transition hover:text-red-400">{{ w.flag }} {{ w.driver }}</Link>
                                <span v-else>{{ w.flag }} {{ w.driver }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <Link v-if="hasRoute('f2.race')" :href="route('f2.race', [w.race_slug, 'feature'])" class="text-sm text-zinc-500 transition hover:text-zinc-300">Резултати →</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </TableShell>
        </section>

        <div class="mt-8 grid gap-8 lg:grid-cols-2">
            <RecentWinners :winners="lastWinners" />

            <div v-if="lastRace">
                <h2 class="mb-3 font-display text-lg font-bold text-white">Последно състезание <span class="text-sm font-normal text-zinc-500">{{ lastRace.year }}</span></h2>
                <TableShell>
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-zinc-800/60">
                            <tr v-for="r in lastRace.top5" :key="r.position" class="bg-zinc-900/40">
                                <td class="px-4 py-2.5 w-12 font-bold tabular-nums text-zinc-400">P{{ r.position }}</td>
                                <td class="px-4 py-2.5 font-semibold text-white">{{ r.driver }}</td>
                                <td class="px-4 py-2.5 text-right text-zinc-300">
                                    <span class="inline-flex items-center gap-2">
                                        <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: r.color ?? NEUTRAL_DOT_COLOR }" />
                                        {{ r.team ?? '—' }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </TableShell>
            </div>
        </div>
    </PublicLayout>
</template>
