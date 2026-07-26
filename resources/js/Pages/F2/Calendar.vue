<script setup>
import FlagIcon from '@/Components/FlagIcon.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { podiumClass } from '@/utils/racing';
import { hasRoute } from '@/utils/routes';
import { Link, router } from '@inertiajs/vue3';

const props = defineProps({
    season: Number,
    seasons: { type: Array, default: () => [] },
    rounds: { type: Array, default: () => [] },
});

const goToSeason = (e) => router.visit(route('f2.calendar.year', e.target.value));
</script>

<template>

    <PublicLayout>
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <Link :href="route('f2')" class="text-sm text-zinc-500 transition hover:text-zinc-300">← Формула 2</Link>
                <h1 class="mt-1 font-display text-2xl font-black sm:text-3xl">Календар Формула 2 <span class="text-red-600">{{ season }}</span></h1>
            </div>
            <select
                v-if="seasons.length"
                :value="season"
                aria-label="Сезон"
                class="rounded-lg border border-zinc-800 bg-zinc-900 px-3 py-2 text-sm text-white focus:border-red-600 focus:outline-none focus:ring-1 focus:ring-red-600"
                @change="goToSeason"
            >
                <option v-for="y in seasons" :key="y" :value="y">{{ y }}</option>
            </select>
        </div>

        <EmptyState v-if="rounds.length === 0">Няма данни за този сезон.</EmptyState>

        <div class="grid gap-4">
            <div v-for="r in rounds" :key="r.round" class="rounded-2xl border border-zinc-800 bg-zinc-900/60 p-5">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-zinc-800 text-sm font-bold tabular-nums">{{ r.round }}</span>
                        <h2 class="font-display text-lg font-bold text-white">{{ r.location }}</h2>
                    </div>
                    <Link
                        v-if="r.circuit_jolpica_id && hasRoute('circuits.show')"
                        :href="route('circuits.show', r.circuit_jolpica_id)"
                        class="text-xs font-medium text-red-500 transition hover:text-red-400"
                    >
                        Пистата →
                    </Link>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div v-for="(s, key) in { sprint: r.sprint, feature: r.feature }" :key="key" class="rounded-xl border border-zinc-800 bg-zinc-950/60 p-3">
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-xs font-bold uppercase tracking-wide" :class="key === 'feature' ? 'text-red-400' : 'text-sky-400'">
                                {{ key === 'feature' ? 'Главно' : 'Спринт' }}
                            </span>
                            <Link
                                v-if="s"
                                :href="route('f2.race', [r.slug, key])"
                                class="text-xs text-zinc-500 transition hover:text-zinc-300"
                            >
                                Резултати →
                            </Link>
                        </div>
                        <div v-if="s" class="space-y-1">
                            <div v-for="p in s.podium" :key="p.position" class="flex items-center gap-2 text-sm">
                                <span class="w-4 font-bold tabular-nums" :class="podiumClass(p.position) || 'text-zinc-500'">{{ p.position }}</span>
                                <span class="text-zinc-200"><FlagIcon :code="p.flag" class="mr-1" />{{ p.driver }}</span>
                            </div>
                            <div v-if="s.date" class="mt-1 text-xs text-zinc-500">{{ s.date }}</div>
                        </div>
                        <div v-else class="text-sm text-zinc-500">Предстои</div>
                    </div>
                </div>
            </div>
        </div>
    </PublicLayout>
</template>
