<script setup>
import CalendarSubscribe from '@/Components/Calendar/CalendarSubscribe.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    season: Number,
    races: Array,
});

const upcomingRaces = computed(() =>
    props.races.filter((race) => !race.finished).sort((a, b) => a.round - b.round),
);

// Низходящо по кръг — най-скорошното завършено състезание е най-интересно.
const finishedRaces = computed(() =>
    props.races.filter((race) => race.finished).sort((a, b) => b.round - a.round),
);
</script>

<template>
    <Head title="Календар">
        <meta head-key="description" name="description" content="Календар на Формула 1 — всички състезания за сезона с дати, писти и часове в българско време." />
    </Head>

    <PublicLayout>
        <div class="mb-6 flex items-center justify-between gap-3">
            <h1 class="font-display text-2xl font-black sm:text-3xl">Календар <span class="text-red-600">{{ season }}</span></h1>
            <div class="flex items-center gap-3">
                <CalendarSubscribe />
                <Link :href="route('standings')" class="hidden text-sm font-medium text-red-500 transition hover:text-red-400 sm:inline">
                    Класиране →
                </Link>
            </div>
        </div>

        <EmptyState v-if="races.length === 0">
            Все още няма синхронизиран календар.
        </EmptyState>

        <template v-else>
            <section v-if="upcomingRaces.length > 0">
                <h2 class="mb-3 font-display text-lg font-bold">
                    Предстоящи <span class="ml-1 text-sm font-normal text-zinc-500">{{ upcomingRaces.length }}</span>
                </h2>
                <div class="grid gap-3">
                    <Link
                        v-for="race in upcomingRaces"
                        :key="race.id"
                        :href="route('races.show', race.id)"
                        class="group flex items-center justify-between rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 transition duration-200 hover:border-red-600/50 hover:bg-zinc-900"
                    >
                        <div class="flex items-center gap-4">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-zinc-800 text-sm font-bold tabular-nums text-zinc-300 transition group-hover:bg-red-600 group-hover:text-white">
                                {{ race.round }}
                            </span>
                            <div>
                                <div class="font-semibold text-white">
                                    {{ race.name_bg ?? race.name }}
                                    <span v-if="race.has_sprint" class="ml-2 rounded bg-amber-500/15 px-1.5 py-0.5 text-xs font-medium text-amber-400">
                                        Спринт
                                    </span>
                                </div>
                                <div class="text-sm text-zinc-500">{{ race.circuit }}, {{ race.country }}</div>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-sm font-medium tabular-nums text-zinc-200">{{ race.race_at_sofia ?? 'TBC' }}</div>
                            <div class="text-xs text-zinc-500">Предстои</div>
                        </div>
                    </Link>
                </div>
            </section>

            <section v-if="finishedRaces.length > 0" :class="upcomingRaces.length > 0 ? 'mt-10' : ''">
                <p v-if="upcomingRaces.length === 0" class="mb-2 text-sm text-zinc-500">Сезонът приключи.</p>
                <h2 class="mb-3 font-display text-lg font-bold">
                    Приключени <span class="ml-1 text-sm font-normal text-zinc-500">{{ finishedRaces.length }}</span>
                </h2>
                <div class="grid gap-3">
                    <Link
                        v-for="race in finishedRaces"
                        :key="race.id"
                        :href="route('races.show', race.id)"
                        class="group flex items-center justify-between rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 opacity-75 transition duration-200 hover:border-red-600/50 hover:bg-zinc-900 hover:opacity-100"
                    >
                        <div class="flex items-center gap-4">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-zinc-800 text-sm font-bold tabular-nums text-zinc-300 transition group-hover:bg-red-600 group-hover:text-white">
                                {{ race.round }}
                            </span>
                            <div>
                                <div class="font-semibold text-white">
                                    {{ race.name_bg ?? race.name }}
                                    <span v-if="race.has_sprint" class="ml-2 rounded bg-amber-500/15 px-1.5 py-0.5 text-xs font-medium text-amber-400">
                                        Спринт
                                    </span>
                                </div>
                                <div class="text-sm text-zinc-500">{{ race.circuit }}, {{ race.country }}</div>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-sm font-medium tabular-nums text-zinc-200">{{ race.race_at_sofia ?? 'TBC' }}</div>
                            <div class="text-xs font-medium text-emerald-400">Завършено</div>
                        </div>
                    </Link>
                </div>
            </section>
        </template>
    </PublicLayout>
</template>
