<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import StatTile from '@/Components/UI/StatTile.vue';
import BadgeCard from '@/Components/Profile/BadgeCard.vue';
import PredictionBreakdown from '@/Components/Predictions/PredictionBreakdown.vue';
import { hasRoute } from '@/utils/routes';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    profile: Object,
    stats: Object,
    quiz: { type: Object, default: () => ({ points: 0, available: 0 }) },
    streak: { type: Number, default: 0 },
    // Само заключени кръгове — отворена прогноза никога не излиза публично.
    predictionHistory: { type: Array, default: () => [] },
    season: Number,
});

const canOpenDriver = computed(() => hasRoute('drivers.show'));
const canOpenTeam = computed(() => hasRoute('teams.show'));
const earnedBadges = computed(() => (props.profile.badges ?? []).filter((b) => b.earned).length);
</script>

<template>

    <PublicLayout>
        <div class="grid gap-6 md:grid-cols-3">
            <div class="md:col-span-1">
                <div class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-6 text-center">
                    <div class="mx-auto mb-3 flex h-20 w-20 items-center justify-center rounded-full bg-gradient-to-br from-red-600 to-red-800 text-2xl font-black text-white">
                        {{ profile.name.charAt(0).toUpperCase() }}
                    </div>
                    <h1 class="font-display text-2xl font-black text-white sm:text-3xl">{{ profile.name }}</h1>
                    <p v-if="profile.bio" class="mt-2 text-sm text-zinc-400">{{ profile.bio }}</p>

                    <dl class="mt-4 space-y-1 text-sm text-zinc-400">
                        <div v-if="profile.favorite_driver">
                            <dt class="inline font-medium text-zinc-300">Любим пилот:</dt>
                            <Link
                                v-if="canOpenDriver && profile.favorite_driver.slug"
                                :href="route('drivers.show', profile.favorite_driver.slug)"
                                class="transition hover:text-red-400"
                            >
                                {{ profile.favorite_driver.full_name }}
                            </Link>
                            <span v-else>{{ profile.favorite_driver.full_name }}</span>
                        </div>
                        <div v-if="profile.favorite_constructor">
                            <dt class="inline font-medium text-zinc-300">Любим отбор:</dt>
                            <Link
                                v-if="canOpenTeam && profile.favorite_constructor.slug"
                                :href="route('teams.show', profile.favorite_constructor.slug)"
                                class="transition hover:text-red-400"
                            >
                                {{ profile.favorite_constructor.name }}
                            </Link>
                            <span v-else>{{ profile.favorite_constructor.name }}</span>
                        </div>
                    </dl>
                </div>
            </div>

            <div class="space-y-6 md:col-span-2">
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <StatTile
                        v-for="stat in [
                            { label: `точки ${season}`, value: stats.points, accent: true },
                            { label: 'прогнози', value: stats.predictions },
                            { label: 'най-добра', value: stats.best },
                            { label: 'средно', value: stats.average },
                        ]"
                        :key="stat.label"
                        :label="stat.label"
                        :value-class="stat.accent ? 'text-red-600' : 'text-white'"
                    >
                        {{ stat.value }}
                    </StatTile>
                </div>

                <p v-if="streak >= 2" class="flex items-center gap-1.5 text-sm text-zinc-400">
                    <span aria-hidden="true">🔥</span>
                    Серия: <span class="font-bold text-orange-400">{{ streak }}</span> поредни кръга с прогноза
                </p>

                <!-- История на прогнозите: чуждите решения са социалното
                     съдържание на лигата при този брой играчи. -->
                <div v-if="predictionHistory.length" class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-6">
                    <h2 class="mb-4 font-display text-lg font-bold text-white">Прогнози по кръгове</h2>
                    <ul class="space-y-3">
                        <li
                            v-for="entry in predictionHistory"
                            :key="entry.round"
                            class="rounded-lg border border-zinc-800 bg-black/30 p-3"
                        >
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <span class="font-semibold text-white">
                                    <span class="text-zinc-500">Кръг {{ entry.round }} ·</span> {{ entry.race }}
                                </span>
                                <span
                                    v-if="entry.points !== null"
                                    class="shrink-0 font-bold tabular-nums text-red-500"
                                >{{ entry.points }} т.</span>
                                <span v-else class="shrink-0 text-xs text-zinc-500">чака резултати</span>
                            </div>
                            <ol class="mt-1.5 flex flex-wrap gap-x-3 gap-y-1 text-sm text-zinc-400">
                                <li v-for="(name, i) in entry.podium" :key="i" class="flex items-baseline gap-1">
                                    <span class="text-xs">{{ ['🥇', '🥈', '🥉'][i] }}</span>
                                    <span>{{ name ?? '—' }}</span>
                                </li>
                            </ol>
                            <details v-if="entry.breakdown" class="mt-2">
                                <summary class="cursor-pointer text-xs font-medium text-zinc-500 transition hover:text-zinc-300">
                                    Разбивка на точките
                                </summary>
                                <PredictionBreakdown class="mt-2" :breakdown="entry.breakdown" :total="entry.points ?? 0" />
                            </details>
                        </li>
                    </ul>
                </div>

                <div v-if="quiz.available" class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-6">
                    <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="font-display text-lg font-bold text-white">Куиз</h2>
                        <Link :href="route('quiz')" class="text-sm font-medium text-red-500 transition hover:text-red-400">
                            Играй →
                        </Link>
                    </div>
                    <!-- Само точките: знаменателят „/ N въпроса" беше текущият
                         брой в базата — расте с всеки добавен въпрос и правеше
                         целта подвижна, а лентата — безсмислена. -->
                    <div class="flex items-end gap-2">
                        <span class="font-display text-3xl font-black leading-none tabular-nums text-white">{{ quiz.points }}</span>
                        <span class="pb-0.5 text-sm text-zinc-500">{{ quiz.points === 1 ? 'точка' : 'точки' }}</span>
                    </div>
                    <p class="mt-2 text-xs text-zinc-500">Нови въпроси всеки понеделник.</p>
                </div>

                <div class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-6">
                    <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="font-display text-lg font-bold text-white">Значки</h2>
                        <span class="text-sm tabular-nums text-zinc-500">{{ earnedBadges }} / {{ profile.badges.length }}</span>
                    </div>

                    <!-- Карти вместо пилюли: условието за печелене стои видимо
                         под всяка значка. Дотук беше само в title tooltip, който
                         на телефон не съществува. -->
                    <ul class="grid gap-3 sm:grid-cols-2">
                        <BadgeCard v-for="badge in profile.badges" :key="badge.slug" :badge="badge" />
                    </ul>
                </div>
            </div>
        </div>
    </PublicLayout>
</template>
