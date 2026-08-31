<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import StatTile from '@/Components/UI/StatTile.vue';
import BadgeCard from '@/Components/Profile/BadgeCard.vue';
import { hasRoute } from '@/utils/routes';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    profile: Object,
    stats: Object,
    quiz: { type: Object, default: () => ({ points: 0, available: 0, attempts: 0 }) },
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
