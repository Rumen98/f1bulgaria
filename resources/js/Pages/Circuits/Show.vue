<script setup>
import AllTimeLeaderboard from '@/Components/Circuit/AllTimeLeaderboard.vue';
import CircuitHero from '@/Components/Circuit/CircuitHero.vue';
import CircuitRecords from '@/Components/Circuit/CircuitRecords.vue';
import CircuitTechnical from '@/Components/Circuit/CircuitTechnical.vue';
import RecentWinners from '@/Components/Circuit/RecentWinners.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    circuit: Object,
    technical: { type: Object, default: null },
    standings: Array,
    lastWinners: Array,
    records: Object,
    lastRace: Object,
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

        <div class="mt-8 grid gap-8 lg:grid-cols-2">
            <RecentWinners :winners="lastWinners" />

            <div v-if="lastRace">
                <h2 class="mb-3 text-lg font-bold text-white">Последно състезание <span class="text-sm font-normal text-zinc-500">{{ lastRace.year }}</span></h2>
                <div class="overflow-hidden rounded-xl border border-zinc-800">
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-zinc-800">
                            <tr v-for="r in lastRace.top5" :key="r.position" class="bg-zinc-900/40">
                                <td class="px-4 py-2.5 w-12 font-bold tabular-nums text-zinc-400">P{{ r.position }}</td>
                                <td class="px-4 py-2.5 font-semibold text-white">{{ r.driver }}</td>
                                <td class="px-4 py-2.5 text-right text-zinc-300">
                                    <span class="inline-flex items-center gap-2">
                                        <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: r.color ?? '#52525b' }" />
                                        {{ r.team ?? '—' }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </PublicLayout>
</template>
