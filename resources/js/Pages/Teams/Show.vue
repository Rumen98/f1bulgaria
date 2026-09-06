<script setup>
import TeamBrand from '@/Components/Team/TeamBrand.vue';
import TeamDriverCard from '@/Components/Team/TeamDriverCard.vue';
import TeamNewsList from '@/Components/Team/TeamNewsList.vue';
import TeamStatsBar from '@/Components/Team/TeamStatsBar.vue';
import Card from '@/Components/UI/Card.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SeasonSelect from '@/Components/UI/SeasonSelect.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { TEAM_COLOR_FALLBACK } from '@/utils/racing';
import { hasRoute } from '@/utils/routes';
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const canOpenRace = computed(() => hasRoute('races.show'));
const canOpenDriver = computed(() => hasRoute('drivers.show'));

const props = defineProps({
    team: Object,
    stats: Object,
    drivers: Array,
    news: Array,
    recentResults: Array,
    season: Number,
    seasons: { type: Array, default: () => [] },
    selectedSeason: { type: Number, default: null },
});

const goToSeason = (e) => {
    router.visit(route('teams.show', props.team.slug) + `?season=${e.target.value}`, { preserveScroll: true });
};
</script>

<template>
    <!-- meta/og таговете идват сървърно от App\Support\Seo (виж app.blade.php). -->

    <PublicLayout>
        <div class="flex items-center justify-between gap-3">
            <Link :href="route('teams.index')" class="text-sm text-zinc-500 transition hover:text-zinc-300">← Всички отбори</Link>
            <SeasonSelect v-if="seasons.length > 1" :seasons="seasons" :selected="selectedSeason" prefix="Сезон " @change="goToSeason" />
        </div>

        <!-- Hero -->
        <section
            class="relative mt-3 overflow-hidden rounded-2xl border border-zinc-800 p-6 sm:p-8"
            :style="{ background: `linear-gradient(110deg, ${team.color_hex ?? TEAM_COLOR_FALLBACK}33, #0a0a0a 65%)` }"
        >
            <div class="flex flex-col gap-5 sm:flex-row sm:items-center">
                <div class="h-20 w-20 shrink-0 sm:h-24 sm:w-24">
                    <TeamBrand :name="team.name" :slug="team.slug" :color="team.color_hex ?? TEAM_COLOR_FALLBACK" variant="emblem" />
                </div>
                <div>
                    <div class="flex flex-wrap items-center gap-3">
                        <h1 class="font-display text-3xl font-black sm:text-4xl">
                            {{ team.name_bg ?? team.name }}
                            <!-- Оригиналното изписване — покрива и заявките на латиница. -->
                            <span v-if="team.name_bg && team.name_bg !== team.name" class="ml-2 align-middle text-sm font-medium text-zinc-500">
                                {{ team.name }}
                            </span>
                        </h1>
                        <span
                            class="rounded px-2 py-0.5 text-xs font-semibold uppercase tracking-wide"
                            :class="team.is_active ? 'bg-emerald-500/15 text-emerald-400' : 'bg-amber-500/15 text-amber-400'"
                        >{{ team.is_active ? 'Активен отбор' : 'Легенда' }}</span>
                    </div>
                    <div class="mt-1 flex flex-wrap items-center gap-3 text-zinc-300">
                        <span class="tabular-nums">{{ stats.seasons }} сезона</span>
                        <span v-if="team.first_year" class="text-zinc-600">·</span>
                        <span v-if="team.first_year" class="tabular-nums">{{ team.first_year }}–{{ team.last_year }}</span>
                        <span class="text-zinc-600">·</span>
                        <span class="tabular-nums">{{ stats.wins }} {{ stats.wins === 1 ? 'победа' : 'победи' }}</span>
                    </div>
                    <p class="mt-3 max-w-2xl text-sm text-zinc-400">{{ team.description }}</p>
                </div>
            </div>
        </section>

        <!-- Статистика -->
        <div class="mt-6">
            <TeamStatsBar :stats="stats" />
        </div>

        <!-- Пилоти -->
        <h2 class="mb-3 mt-8 font-display text-lg font-bold text-white">Пилоти</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <TeamDriverCard v-for="d in drivers" :key="d.slug" :driver="d" :color="team.color_hex ?? TEAM_COLOR_FALLBACK" />
        </div>

        <div class="mt-8 grid gap-8 lg:grid-cols-2">
            <!-- Последни резултати -->
            <div>
                <h2 class="mb-3 font-display text-lg font-bold text-white">Последни състезания</h2>
                <EmptyState v-if="recentResults.length === 0">
                    Няма резултати за сезона.
                </EmptyState>
                <div v-else class="space-y-3">
                    <Card v-for="(r, i) in recentResults" :key="i">
                        <div class="flex items-center justify-between">
                            <div class="font-semibold text-white">
                                <Link
                                    v-if="canOpenRace && r.race_id"
                                    :href="route('races.show', r.race_id)"
                                    class="transition hover:text-red-400"
                                >
                                    {{ r.race }}
                                </Link>
                                <span v-else>{{ r.race }}</span>
                            </div>
                            <div class="text-sm font-bold tabular-nums text-red-500">{{ r.points }} т.</div>
                        </div>
                        <div class="mt-1 text-xs text-zinc-500">{{ r.date }}</div>
                        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-zinc-300">
                            <span v-for="(f, j) in r.finishes" :key="j">
                                <span class="font-bold tabular-nums" :class="f.position ? 'text-zinc-200' : 'text-red-500'">{{ f.position ? 'P' + f.position : 'DNF' }}</span>
                                <Link
                                    v-if="canOpenDriver && f.slug"
                                    :href="route('drivers.show', f.slug)"
                                    class="transition hover:text-red-400"
                                >
                                    {{ f.driver }}
                                </Link>
                                <span v-else>{{ f.driver }}</span>
                            </span>
                        </div>
                    </Card>
                </div>
            </div>

            <!-- Новини -->
            <TeamNewsList :news="news" />
        </div>
    </PublicLayout>
</template>
