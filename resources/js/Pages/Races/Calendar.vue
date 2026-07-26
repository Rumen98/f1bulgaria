<script setup>
import CalendarSubscribe from '@/Components/Calendar/CalendarSubscribe.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { useCountdown } from '@/composables/useCountdown';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

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

// Дефолт „Предстоящи"; след края на сезона няма какво да предстои → „Приключени".
const tab = ref(props.races.some((race) => !race.finished) ? 'upcoming' : 'finished');

const visibleRaces = computed(() => (tab.value === 'upcoming' ? upcomingRaces.value : finishedRaces.value));

// Звездата на страницата — следващото състезание, с брояч.
const nextRace = computed(() => upcomingRaces.value[0] ?? null);
const isNext = (race) => tab.value === 'upcoming' && race.id === nextRace.value?.id;

const { days, hours, minutes, finished: countdownDone } = useCountdown(() => nextRace.value?.race_at_utc ?? null);

const countdownText = computed(() => {
    if (nextRace.value?.race_at_utc == null || countdownDone.value) {
        return null;
    }
    if (days.value > 0) {
        return `след ${days.value} ${days.value === 1 ? 'ден' : 'дни'} ${hours.value} ч`;
    }
    if (hours.value > 0) {
        return `след ${hours.value} ч ${minutes.value} мин`;
    }
    return `след ${minutes.value} мин`;
});
</script>

<template>
    <!-- meta/og таговете идват сървърно от App\Support\Seo (виж app.blade.php). -->
    <Head title="Календар" />

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
            <div class="mb-5 inline-flex rounded-lg border border-zinc-800 bg-zinc-900/60 p-1">
                <button
                    type="button"
                    class="rounded-md px-4 py-2 text-sm font-semibold transition"
                    :class="tab === 'upcoming' ? 'bg-red-600 text-white' : 'text-zinc-400 hover:text-white'"
                    :aria-pressed="tab === 'upcoming'"
                    @click="tab = 'upcoming'"
                >
                    Предстоящи <span class="ml-1 tabular-nums opacity-70">{{ upcomingRaces.length }}</span>
                </button>
                <button
                    type="button"
                    class="rounded-md px-4 py-2 text-sm font-semibold transition"
                    :class="tab === 'finished' ? 'bg-red-600 text-white' : 'text-zinc-400 hover:text-white'"
                    :aria-pressed="tab === 'finished'"
                    @click="tab = 'finished'"
                >
                    Приключени <span class="ml-1 tabular-nums opacity-70">{{ finishedRaces.length }}</span>
                </button>
            </div>

            <p v-if="tab === 'upcoming' && upcomingRaces.length === 0" class="mb-3 text-sm text-zinc-500">
                Сезонът приключи — виж резултатите в „Приключени".
            </p>

            <div class="grid gap-3">
                <Link
                    v-for="race in visibleRaces"
                    :key="race.id"
                    :href="route('races.show', race.id)"
                    class="group flex items-center justify-between rounded-xl border p-4 transition duration-200 hover:bg-zinc-900"
                    :class="isNext(race)
                        ? 'border-red-600/60 bg-zinc-900 shadow-[0_0_25px_rgba(225,6,0,0.12)] hover:border-red-500'
                        : 'border-zinc-800 bg-zinc-900/60 hover:border-red-600/50'"
                >
                    <div class="flex items-center gap-4">
                        <span
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-sm font-bold tabular-nums transition"
                            :class="isNext(race)
                                ? 'bg-red-600 text-white'
                                : 'bg-zinc-800 text-zinc-300 group-hover:bg-red-600 group-hover:text-white'"
                        >
                            {{ race.round }}
                        </span>
                        <div>
                            <div class="font-semibold text-white">
                                <span v-if="isNext(race)" class="mr-2 rounded bg-red-600 px-1.5 py-0.5 text-[11px] font-bold uppercase tracking-wider text-white">
                                    Следващо
                                </span>
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
                        <div v-if="isNext(race) && countdownText" class="font-display text-xs font-bold tabular-nums text-red-500">
                            {{ countdownText }}
                        </div>
                        <div v-else-if="race.finished" class="text-xs font-medium text-emerald-400">Завършено</div>
                        <div v-else class="text-xs text-zinc-500">Предстои</div>
                    </div>
                </Link>
            </div>
        </template>
    </PublicLayout>
</template>
