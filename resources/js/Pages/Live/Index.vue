<script setup>
import TableShell from '@/Components/UI/TableShell.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { podiumClass } from '@/utils/racing';
import { Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    session: { type: Object, default: null },
    standings: { type: Array, default: () => [] },
    nextRace: { type: Object, default: null },
    updated_at: { type: String, default: null },
    credentialsConfigured: { type: Boolean, default: true },
});

const session = ref(props.session);
const rows = ref(props.standings);
const updatedAt = ref(props.updated_at);
const refreshing = ref(false);
const now = ref(Date.now());

let pollTimer = null;
let clockTimer = null;

const refresh = async () => {
    refreshing.value = true;
    try {
        const res = await fetch(route('live.refresh'), { headers: { Accept: 'application/json' } });
        if (res.ok) {
            const data = await res.json();
            session.value = data.session;
            rows.value = data.standings ?? [];
            updatedAt.value = data.updated_at;
        }
    } catch (e) {
        // тихо — пазим текущите данни, без да чупим UI
    } finally {
        refreshing.value = false;
    }
};

onMounted(() => {
    clockTimer = setInterval(() => (now.value = Date.now()), 1000);
    pollTimer = setInterval(refresh, 5000);
});
onBeforeUnmount(() => {
    clearInterval(pollTimer);
    clearInterval(clockTimer);
});

const countdown = computed(() => {
    if (!session.value?.ends_at) {
        return null;
    }
    const diff = new Date(session.value.ends_at).getTime() - now.value;
    if (diff <= 0) {
        return 'край';
    }
    const m = Math.floor(diff / 60000);
    const s = Math.floor((diff % 60000) / 1000);
    return `${m}:${String(s).padStart(2, '0')}`;
});

const updatedLabel = computed(() => {
    if (!updatedAt.value) {
        return '';
    }
    const secs = Math.max(0, Math.round((now.value - new Date(updatedAt.value).getTime()) / 1000));
    return secs < 5 ? 'сега' : `преди ${secs}с`;
});

const isQualifying = computed(() => session.value?.is_qualifying ?? false);
const cutoffs = computed(() => session.value?.cutoffs ?? []);

const posClass = (p) => podiumClass(p) || 'text-zinc-500';

const tire = (compound) => {
    const map = {
        SOFT: { l: 'S', c: 'text-red-500 border-red-500' },
        MEDIUM: { l: 'M', c: 'text-yellow-400 border-yellow-400' },
        HARD: { l: 'H', c: 'text-zinc-200 border-zinc-200' },
        INTERMEDIATE: { l: 'I', c: 'text-emerald-400 border-emerald-400' },
        WET: { l: 'W', c: 'text-sky-400 border-sky-400' },
    };
    return map[compound] ?? null;
};

const fmtSector = (v) => (v ? v.toFixed(3) : '—');

// Лилаво = сесиен рекорд за сектора; зелено = лично най-добро (показваме него); иначе сиво.
const sectorClass = (row, n) => {
    if (row[`sector${n}_overall`]) {
        return 'text-purple-400';
    }
    return row[`sector${n}_best`] ? 'text-emerald-400' : 'text-zinc-500';
};

const nextRaceCountdown = computed(() => {
    if (!props.nextRace?.starts_at) {
        return null;
    }
    const diff = new Date(props.nextRace.starts_at).getTime() - now.value;
    if (diff <= 0) {
        return null;
    }
    const days = Math.floor(diff / 86400000);
    const hours = Math.floor((diff % 86400000) / 3600000);
    return days > 0 ? `${days} дни ${hours} ч` : `${hours} ч`;
});
</script>

<template>

    <PublicLayout>
        <!-- Активна сесия -->
        <template v-if="session">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <span class="relative flex h-3 w-3">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-500 opacity-75" />
                        <span class="relative inline-flex h-3 w-3 rounded-full bg-red-600" />
                    </span>
                    <h1 class="font-display text-2xl font-black sm:text-3xl">
                        LIVE — <span class="text-red-600">{{ session.circuit }}</span> · {{ session.name }}
                    </h1>
                    <span v-if="isQualifying" class="rounded bg-purple-500/15 px-2 py-0.5 text-xs font-bold uppercase tracking-wide text-purple-300">Квалификация</span>
                </div>
                <div class="flex items-center gap-3 text-sm text-zinc-400">
                    <span v-if="countdown" class="tabular-nums">⏳ {{ countdown }}</span>
                    <span class="flex items-center gap-1">
                        <span class="h-1.5 w-1.5 rounded-full" :class="refreshing ? 'animate-pulse bg-emerald-400' : 'bg-zinc-600'" />
                        {{ updatedLabel }}
                    </span>
                </div>
            </div>

            <TableShell>
                <!-- Всички колони са винаги видими — на тясно таблицата скролва
                     хоризонтално (TableShell), вместо да крие секторите/отбора. -->
                <table class="w-full whitespace-nowrap text-sm">
                    <thead class="bg-zinc-900/80 text-xs uppercase tracking-wide text-zinc-500">
                        <tr>
                            <th class="px-2 py-2 text-left sm:px-3">#</th>
                            <th class="px-2 py-2 text-left sm:px-3">Пилот</th>
                            <th class="px-3 py-2 text-left">Отбор</th>
                            <th class="px-2 py-2 text-right sm:px-3">Най-добра</th>
                            <th class="px-3 py-2 text-right">Последна</th>
                            <th class="px-2 py-2 text-right">С1</th>
                            <th class="px-2 py-2 text-right">С2</th>
                            <th class="px-2 py-2 text-right">С3</th>
                            <th class="px-2 py-2 text-right">Инт.</th>
                            <th class="px-2 py-2 text-center">Гума</th>
                            <th class="px-2 py-2 text-right">Об.</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-800/60">
                        <template v-for="row in rows" :key="row.driver_number">
                        <tr class="bg-zinc-900/40 transition hover:bg-zinc-800/40">
                            <td class="px-2 py-2.5 font-black tabular-nums sm:px-3" :class="posClass(row.position)">{{ row.position }}</td>
                            <td class="px-2 py-2.5 sm:px-3">
                                <span class="inline-flex items-center gap-2">
                                    <span class="h-4 w-1 rounded" :style="{ backgroundColor: row.team_colour }" />
                                    <span class="font-semibold text-white">{{ row.acronym ?? row.name }}</span>
                                    <span v-if="isQualifying && row.position === 1 && row.best_lap_time" title="Pole">🏁</span>
                                </span>
                            </td>
                            <td class="px-3 py-2.5 text-zinc-400">{{ row.team_name }}</td>
                            <td class="px-2 py-2.5 text-right font-bold tabular-nums sm:px-3" :class="row.is_overall_best ? 'text-purple-400' : 'text-white'">{{ row.best_lap_time ?? '—' }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-zinc-400">{{ row.last_lap_time ?? '—' }}</td>
                            <td class="px-2 py-2.5 text-right tabular-nums" :class="sectorClass(row, 1)">{{ fmtSector(row.sector1_best) }}</td>
                            <td class="px-2 py-2.5 text-right tabular-nums" :class="sectorClass(row, 2)">{{ fmtSector(row.sector2_best) }}</td>
                            <td class="px-2 py-2.5 text-right tabular-nums" :class="sectorClass(row, 3)">{{ fmtSector(row.sector3_best) }}</td>
                            <td class="px-2 py-2.5 text-right tabular-nums text-zinc-400">{{ row.gap_to_leader ?? '' }}</td>
                            <td class="px-2 py-2.5 text-center">
                                <span v-if="tire(row.current_tire)" class="inline-flex h-6 w-6 items-center justify-center rounded-full border text-xs font-bold" :class="tire(row.current_tire).c">
                                    {{ tire(row.current_tire).l }}
                                </span>
                                <span v-else class="text-zinc-600">—</span>
                            </td>
                            <td class="px-2 py-2.5 text-right tabular-nums text-zinc-500">{{ row.laps_completed }}</td>
                        </tr>
                        <tr v-if="cutoffs.includes(row.position)" :key="`cut-${row.position}`" class="bg-red-950/30">
                            <td colspan="11" class="px-3 py-1 text-center text-xs font-bold uppercase tracking-wider text-red-400">
                                ⸻ граница на отпадане (P{{ row.position }}) ⸻
                            </td>
                        </tr>
                        </template>
                    </tbody>
                </table>
                <div v-if="rows.length === 0" class="p-8 text-center text-zinc-500">
                    Изчакване на данни от сесията…
                </div>
            </TableShell>
            <p class="mt-3 text-xs text-zinc-500">Данни: OpenF1 · обновяване на всеки 5 секунди</p>
        </template>

        <!-- Няма активна сесия -->
        <div v-else class="rounded-xl border border-zinc-800 bg-zinc-900/40 p-10 text-center">
            <div class="text-4xl">🏁</div>
            <h1 class="mt-3 font-display text-2xl font-black">Няма активна сесия в момента</h1>
            <p class="mt-2 text-zinc-400">Live таймингът се появява по време на тренировки, квалификации и състезания.</p>

            <p v-if="!credentialsConfigured" class="mx-auto mt-4 max-w-md rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-300">
                ⚠️ Live данните изискват OpenF1 акаунт (OPENF1_USERNAME/PASSWORD). Без тях таймингът остава недостъпен по време на сесии.
            </p>

            <div v-if="nextRace" class="mx-auto mt-6 max-w-sm rounded-xl border border-zinc-800 bg-zinc-950 p-5">
                <div class="text-xs uppercase tracking-wide text-zinc-500">Следващо състезание</div>
                <div class="mt-1 font-bold text-white">{{ nextRace.name }}</div>
                <div v-if="nextRaceCountdown" class="mt-1 font-display text-sm tabular-nums text-red-500">след {{ nextRaceCountdown }}</div>
            </div>

            <Link :href="route('calendar')" class="mt-6 inline-block text-sm font-medium text-red-500 transition hover:text-red-400">
                Виж целия календар →
            </Link>
        </div>
    </PublicLayout>
</template>
