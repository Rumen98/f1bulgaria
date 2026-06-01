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
            <Link :href="route('calendar')" class="text-sm text-gray-500 hover:underline">← Календар</Link>
            <h1 class="mt-2 text-2xl font-bold">
                Кръг {{ race.round }} — {{ race.name }}
            </h1>
            <p class="text-gray-500">{{ race.circuit }}, {{ race.country }}</p>
        </div>

        <div class="grid gap-8 lg:grid-cols-3">
            <!-- Разписание + резултати -->
            <div class="space-y-6 lg:col-span-2">
                <section class="rounded-lg border border-gray-200 bg-white p-5">
                    <h2 class="mb-3 font-semibold">Разписание (софийско време)</h2>
                    <ul class="divide-y divide-gray-100 text-sm">
                        <li v-for="s in race.sessions" :key="s.type" class="flex justify-between py-2">
                            <span class="text-gray-600">{{ s.label }}</span>
                            <span class="font-medium">{{ s.scheduled_at_sofia ?? 'TBC' }}</span>
                        </li>
                    </ul>
                </section>

                <section v-if="finished" class="rounded-lg border border-gray-200 bg-white p-5">
                    <h2 class="mb-3 font-semibold">Резултати</h2>
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs uppercase text-gray-500">
                            <tr>
                                <th class="py-2">Поз.</th>
                                <th class="py-2">Пилот</th>
                                <th class="py-2 text-right">Точки</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="r in race.results" :key="r.driver.id">
                                <td class="py-2 font-medium">{{ r.position ?? 'DNF' }}</td>
                                <td class="py-2">
                                    {{ r.driver.full_name }}
                                    <span v-if="r.fastest_lap" title="Най-бърза обиколка">🔥</span>
                                </td>
                                <td class="py-2 text-right">{{ r.points }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>

            <!-- Прогноза -->
            <aside class="rounded-lg border border-gray-200 bg-white p-5">
                <h2 class="mb-1 font-semibold">Твоята прогноза</h2>

                <p v-if="lockDeadline" class="mb-4 text-xs text-gray-500">
                    Заключване: {{ lockDeadline }}
                </p>

                <template v-if="!user">
                    <p class="text-sm text-gray-600">
                        <Link :href="route('login')" class="font-medium text-red-600 hover:underline">Влез</Link>
                        за да подадеш прогноза.
                    </p>
                </template>

                <template v-else-if="finished && userPrediction">
                    <div class="rounded-md bg-gray-50 p-4 text-center">
                        <div class="text-3xl font-bold text-red-600">{{ userPrediction.points ?? 0 }}</div>
                        <div class="text-xs uppercase text-gray-500">точки за това състезание</div>
                    </div>
                </template>

                <template v-else-if="locked">
                    <p class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800">
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
