<script setup>
import PredictionForm from '@/Components/PredictionForm.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    race: Object,
    locked: Boolean,
    lockDeadline: String,
    userPrediction: Object,
    drivers: Array,
});

const user = computed(() => usePage().props.auth?.user);
const finished = computed(() => props.race.results && props.race.results.length > 0);
</script>

<template>
    <Head :title="race.name" />

    <PublicLayout>
        <div class="mb-6">
            <Link :href="route('calendar')" class="text-sm text-zinc-500 transition hover:text-zinc-300">← Календар</Link>
            <h1 class="mt-2 text-2xl font-black sm:text-3xl">
                <span class="text-red-600">Кръг {{ race.round }}</span> — {{ race.name }}
            </h1>
            <p class="text-zinc-500">{{ race.circuit }}, {{ race.country }}</p>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <section class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-5">
                    <h2 class="mb-3 font-semibold text-white">Разписание <span class="text-xs font-normal text-zinc-500">(софийско време)</span></h2>
                    <ul class="divide-y divide-zinc-800 text-sm">
                        <li v-for="s in race.sessions" :key="s.type" class="flex justify-between py-2">
                            <span class="text-zinc-400">{{ s.label }}</span>
                            <span class="font-medium tabular-nums text-zinc-200">{{ s.scheduled_at_sofia ?? 'TBC' }}</span>
                        </li>
                    </ul>
                </section>

                <section v-if="finished" class="overflow-hidden rounded-xl border border-zinc-800 bg-zinc-900/60">
                    <h2 class="border-b border-zinc-800 px-5 py-3 font-semibold text-white">Резултати</h2>
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs uppercase tracking-wide text-zinc-500">
                            <tr>
                                <th class="px-5 py-2 w-14">Поз.</th>
                                <th class="px-5 py-2">Пилот</th>
                                <th class="px-5 py-2 text-right">Точки</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-800">
                            <tr v-for="r in race.results" :key="r.driver.id" class="transition hover:bg-zinc-800/40">
                                <td class="px-5 py-2 font-bold tabular-nums" :class="r.position ? 'text-zinc-200' : 'text-red-500'">{{ r.position ?? 'DNF' }}</td>
                                <td class="px-5 py-2 text-zinc-200">
                                    {{ r.driver.full_name }}
                                    <span v-if="r.fastest_lap" title="Най-бърза обиколка">🔥</span>
                                </td>
                                <td class="px-5 py-2 text-right font-medium tabular-nums text-white">{{ r.points }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>

            <aside class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-5">
                <h2 class="mb-1 font-semibold text-white">Твоята прогноза</h2>

                <p v-if="lockDeadline" class="mb-4 text-xs text-zinc-500">
                    Заключване: {{ lockDeadline }}
                </p>

                <template v-if="!user">
                    <p class="text-sm text-zinc-400">
                        <Link :href="route('login')" class="font-medium text-red-500 hover:text-red-400">Влез</Link>
                        за да подадеш прогноза.
                    </p>
                </template>

                <template v-else-if="finished && userPrediction">
                    <div class="rounded-lg border border-zinc-800 bg-black/40 p-4 text-center">
                        <div class="text-3xl font-black text-red-600">{{ userPrediction.points ?? 0 }}</div>
                        <div class="text-xs uppercase tracking-wide text-zinc-500">точки за това състезание</div>
                    </div>
                </template>

                <template v-else-if="locked">
                    <p class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm text-amber-300">
                        Прогнозите са заключени.
                    </p>
                </template>

                <PredictionForm
                    v-else
                    :race-id="race.id"
                    :drivers="drivers"
                    :prediction="userPrediction"
                    :locked="locked"
                />
            </aside>
        </div>
    </PublicLayout>
</template>
