<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import StatTile from '@/Components/UI/StatTile.vue';
import { Link } from '@inertiajs/vue3';

defineProps({
    profile: Object,
    stats: Object,
    quiz: { type: Object, default: () => ({ points: 0, available: 0, attempts: 0 }) },
    game: { type: Object, default: null },
    season: Number,
});

const formatLap = (ms) => {
    const minutes = Math.floor(ms / 60000);
    const seconds = ((ms % 60000) / 1000).toFixed(3).padStart(6, '0');
    return `${minutes}:${seconds}`;
};
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
                            {{ profile.favorite_driver.full_name }}
                        </div>
                        <div v-if="profile.favorite_constructor">
                            <dt class="inline font-medium text-zinc-300">Любим отбор:</dt>
                            {{ profile.favorite_constructor.name }}
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

                <div v-if="quiz.available" class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-6">
                    <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="font-display text-lg font-bold text-white">Куиз</h2>
                        <Link :href="route('quiz')" class="text-sm font-medium text-red-500 transition hover:text-red-400">
                            Играй →
                        </Link>
                    </div>
                    <div class="flex items-end gap-2">
                        <span class="font-display text-3xl font-black leading-none tabular-nums text-white">{{ quiz.points }}</span>
                        <span class="pb-0.5 text-sm text-zinc-500">/ {{ quiz.available }} покорени въпроса</span>
                    </div>
                    <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-zinc-800">
                        <div
                            class="h-full bg-gradient-to-r from-red-600 to-amber-400"
                            :style="{ width: (quiz.available ? (quiz.points / quiz.available) * 100 : 0) + '%' }"
                        />
                    </div>
                    <p v-if="quiz.attempts" class="mt-2 text-xs text-zinc-500">
                        {{ quiz.attempts }} изиграни кръга<template v-if="quiz.best_score !== null">, най-добър {{ quiz.best_score }}/{{ quiz.best_total }}</template>.
                    </p>
                </div>

                <!-- Хронометърът: покорени писти + най-силни времена -->
                <div v-if="game && game.tracks_played > 0" class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-6">
                    <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="font-display text-lg font-bold text-white">Хронометър</h2>
                        <Link :href="route('game')" class="text-sm font-medium text-red-500 transition hover:text-red-400">
                            Карай →
                        </Link>
                    </div>
                    <div class="flex items-end gap-2">
                        <span class="font-display text-3xl font-black leading-none tabular-nums text-white">{{ game.tracks_played }}</span>
                        <span class="pb-0.5 text-sm text-zinc-500">/ {{ game.total_tracks }} покорени писти</span>
                        <span v-if="game.firsts > 0" class="ml-auto pb-0.5 text-sm font-semibold text-amber-400">
                            🏆 {{ game.firsts }}× №1
                        </span>
                    </div>
                    <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-zinc-800">
                        <div
                            class="h-full bg-gradient-to-r from-red-600 to-fuchsia-500"
                            :style="{ width: (game.total_tracks ? (game.tracks_played / game.total_tracks) * 100 : 0) + '%' }"
                        />
                    </div>
                    <ul v-if="game.best_laps.length" class="mt-3 space-y-1 text-sm">
                        <li
                            v-for="lap in game.best_laps"
                            :key="lap.track"
                            class="flex items-baseline justify-between gap-3 text-zinc-300"
                        >
                            <span class="truncate">
                                <span v-if="lap.rank1" title="Рекорд на пистата">🏆</span>
                                {{ lap.name }}
                            </span>
                            <span class="font-mono tabular-nums" :class="lap.rank1 ? 'text-fuchsia-300' : 'text-zinc-400'">
                                {{ formatLap(lap.lap_ms) }}
                            </span>
                        </li>
                    </ul>
                </div>

                <div class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-6">
                    <h2 class="mb-4 font-display text-lg font-bold text-white">Значки</h2>
                    <div v-if="profile.badges.length === 0" class="text-sm text-zinc-500">
                        Все още няма спечелени значки.
                    </div>
                    <div v-else class="flex flex-wrap gap-3">
                        <div
                            v-for="badge in profile.badges"
                            :key="badge.slug"
                            class="flex items-center gap-2 rounded-full border border-amber-500/30 bg-amber-500/10 px-3 py-1.5 text-sm"
                            :title="badge.description"
                        >
                            <span aria-hidden="true">🏅</span>
                            <span class="font-medium text-amber-300">{{ badge.name }}</span>
                            <span class="sr-only">{{ badge.description }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </PublicLayout>
</template>
