<script setup>
import CalendarSubscribe from '@/Components/Calendar/CalendarSubscribe.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { useCountdown } from '@/composables/useCountdown';
import { NEUTRAL_DOT_COLOR } from '@/utils/racing';
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    season: Number,
    races: Array,
});

// Делим по ЧАСОВНИК (`held`), не по наличие на резултати: източникът им
// закъснява с часове, а изкараното състезание не бива да стои като предстоящо.
const upcomingRaces = computed(() =>
    props.races.filter((race) => !race.held).sort((a, b) => a.round - b.round),
);

// Низходящо по кръг — най-скорошното завършено състезание е най-интересно.
const finishedRaces = computed(() =>
    props.races.filter((race) => race.held).sort((a, b) => b.round - a.round),
);

// Дефолт „Предстоящи"; след края на сезона няма какво да предстои → „Приключени".
const tab = ref(props.races.some((race) => !race.held) ? 'upcoming' : 'finished');

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
                    class="group flex items-start gap-3 rounded-xl border p-4 transition duration-200 hover:bg-zinc-900 sm:items-center sm:gap-4"
                    :class="isNext(race)
                        ? 'border-red-600/60 bg-zinc-900 shadow-[0_0_25px_rgba(225,6,0,0.12)] hover:border-red-500'
                        : 'border-zinc-800 bg-zinc-900/60 hover:border-red-600/50'"
                >
                    <span
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-sm font-bold tabular-nums transition"
                        :class="isNext(race)
                            ? 'bg-red-600 text-white'
                            : 'bg-zinc-800 text-zinc-300 group-hover:bg-red-600 group-hover:text-white'"
                    >
                        {{ race.round }}
                    </span>
                    <!-- На телефон часът минава ПОД името: две колони в 360px
                         чупят и заглавието, и датата на срички. От sm нагоре
                         се връща класическият двуколонен ред. -->
                    <div class="min-w-0 flex-1 sm:flex sm:items-center sm:justify-between sm:gap-4">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span v-if="isNext(race)" class="rounded bg-red-600 px-1.5 py-0.5 text-[11px] font-bold uppercase tracking-wider text-white">
                                    Следващо
                                </span>
                                <span class="font-semibold text-white">{{ race.name_bg ?? race.name }}</span>
                                <span v-if="race.has_sprint" class="rounded bg-amber-500/15 px-1.5 py-0.5 text-xs font-medium text-amber-400">
                                    Спринт
                                </span>
                            </div>
                            <div class="mt-0.5 truncate text-sm text-zinc-500">{{ race.circuit }}, {{ race.country }}</div>
                            <!-- Победителят: за минал кръг „Завършено" не казва
                                 нищо, а името е самото съдържание. -->
                            <div v-if="race.winner" class="mt-1 flex items-center gap-1.5 truncate text-sm">
                                <span aria-hidden="true">🏆</span>
                                <span
                                    class="h-2 w-2 shrink-0 rounded-full"
                                    :style="{ backgroundColor: race.winner.color ?? NEUTRAL_DOT_COLOR }"
                                />
                                <span class="truncate font-medium text-zinc-300">{{ race.winner.name }}</span>
                                <span v-if="race.winner.team" class="truncate text-xs text-zinc-500">· {{ race.winner.team }}</span>
                            </div>
                        </div>
                        <div class="mt-2 flex flex-wrap items-baseline gap-x-2 sm:mt-0 sm:block sm:shrink-0 sm:text-right">
                            <div class="whitespace-nowrap text-sm font-medium tabular-nums text-zinc-200">{{ race.race_at_sofia ?? 'TBC' }}</div>
                            <div v-if="isNext(race) && countdownText" class="whitespace-nowrap font-display text-xs font-bold tabular-nums text-red-500">
                                {{ countdownText }}
                            </div>
                            <div v-else-if="race.finished" class="whitespace-nowrap text-xs font-medium text-emerald-400">Завършено</div>
                            <!-- Изкарано, но източникът още не е публикувал класацията.
                                 Без този надпис изглежда като че данните липсват. -->
                            <div v-else-if="race.held" class="whitespace-nowrap text-xs text-amber-400">Чакаме резултатите</div>
                            <div v-else class="whitespace-nowrap text-xs text-zinc-500">Предстои</div>
                        </div>
                    </div>
                </Link>
            </div>
        </template>
    </PublicLayout>
</template>
