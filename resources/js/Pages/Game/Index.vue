<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { formatDelta, formatLapTime } from '@/game/format.js';
import { Head, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref, shallowRef } from 'vue';

const props = defineProps({
    tracks: { type: Array, default: () => [] },
});

const page = usePage();
const authUser = computed(() => page.props.auth?.user ?? null);

const canvas = ref(null);
const game = shallowRef(null);
const selectedTrack = ref(null);
const loading = ref(false);
const error = ref(null);
const transmission = ref('auto'); // 'auto' | 'manual' (ръчна: W нагоре, S надолу)
const preStart = ref(false); // pre-start екран (избор трансмисия + управление) преди обиколката

const emptyTelemetry = () => ({
    speed: 0,
    rpm: 4000,
    gear: 1,
    lapTime: null,
    lastLap: null,
    bestLap: null,
    sector: 1,
    sectors: [null, null, null],
    lapValid: true,
    started: false,
    phase: 'formation',
    recovering: false,
    recoverCount: 0,
    recoverRestart: false,
    gated: false,
    warnings: 0,
    maxWarnings: 3,
});

const telemetry = ref(emptyTelemetry());

// ── Класация / резултат ───────────────────────────────────────────────────
// Лилавите рекорди на пистата (обиколка + по сектори), топ класация и резултатът
// от току-що завършената квалификационна обиколка.
const bests = ref({ lap_ms: null, sectors_ms: [null, null, null] });
const userBests = ref({ lap_ms: null, sectors_ms: [null, null, null] });
const leaderboard = ref([]);
const result = ref(null); // { lapMs, sectorsMs: [..], valid }
const resultMeta = ref(null); // отговорът на сървъра: purple_lap, purple_sectors, rank…
const submitting = ref(false);
const submitError = ref(null);

const fetchLeaderboard = async (slug) => {
    try {
        const { data } = await window.axios.get(`/game/leaderboard/${slug}`);
        bests.value = data.bests ?? { lap_ms: null, sectors_ms: [null, null, null] };
        userBests.value = data.user_bests ?? { lap_ms: null, sectors_ms: [null, null, null] };
        leaderboard.value = data.top ?? [];
    } catch {
        // Класацията е бонус — липсата ѝ не бива да чупи играта.
        bests.value = { lap_ms: null, sectors_ms: [null, null, null] };
        userBests.value = { lap_ms: null, sectors_ms: [null, null, null] };
        leaderboard.value = [];
    }
};

// Финал на квалификационната обиколка → резултатен екран + (ако е валидна и има
// вход) запис в класацията.
const onFinish = (res) => {
    result.value = res;
    resultMeta.value = null;
    submitError.value = null;

    if (res.valid && authUser.value) {
        submitLap(res);
    }
};

const submitLap = async (res) => {
    if (!selectedTrack.value) {
        return;
    }

    submitting.value = true;
    submitError.value = null;

    try {
        const { data } = await window.axios.post('/game/lap', {
            track: selectedTrack.value.slug,
            lap_ms: res.lapMs,
            sectors: res.sectorsMs,
        });
        resultMeta.value = data;
        bests.value = data.bests ?? bests.value; // включва и тази обиколка
        userBests.value = data.user_bests ?? userBests.value;
        leaderboard.value = data.top ?? leaderboard.value;
    } catch (e) {
        submitError.value =
            e?.response?.data?.message ?? 'Времето не се записа. Опитай пак.';
    } finally {
        submitting.value = false;
    }
};

const newLap = () => {
    result.value = null;
    resultMeta.value = null;
    submitError.value = null;
    game.value?.reset(true);
};

// Лилаво = рекорд на пистата. Докато сървърът не отговори, сравняваме локално
// спрямо рекордите отпреди обиколката; после ползваме авторитетния отговор.
const lapIsPurple = computed(() => {
    if (!result.value || !result.value.valid) {
        return false;
    }
    if (resultMeta.value) {
        return resultMeta.value.purple_lap;
    }
    // Строго < като сървъра (изравняване не е нов рекорд на пистата).
    return bests.value.lap_ms === null || result.value.lapMs < bests.value.lap_ms;
});

const sectorIsPurple = (i) => {
    if (!result.value || !result.value.valid || result.value.sectorsMs[i] === null) {
        return false;
    }
    if (resultMeta.value) {
        return resultMeta.value.purple_sectors?.[i] ?? false;
    }
    const best = bests.value.sectors_ms[i];
    return best === null || result.value.sectorsMs[i] < best;
};

// Цвят на сектор/обиколка (F1): лилаво = рекорд на всички (има предимство),
// зелено = личен рекорд, жълто = по-бавно от личния рекорд.
const sectorState = (i) => {
    if (!result.value || !result.value.valid || result.value.sectorsMs[i] === null) {
        return 'none';
    }
    if (sectorIsPurple(i)) {
        return 'purple';
    }
    if (resultMeta.value) {
        return resultMeta.value.green_sectors?.[i] ? 'green' : 'yellow';
    }
    const pb = userBests.value.sectors_ms[i];
    return pb === null || result.value.sectorsMs[i] <= pb ? 'green' : 'yellow';
};

const lapState = computed(() => {
    if (!result.value || !result.value.valid) {
        return 'none';
    }
    if (lapIsPurple.value) {
        return 'purple';
    }
    if (resultMeta.value) {
        return resultMeta.value.personal_best ? 'green' : 'yellow';
    }
    const pb = userBests.value.lap_ms;
    return pb === null || result.value.lapMs <= pb ? 'green' : 'yellow';
});

const CELL_CLASS = {
    purple: 'border-fuchsia-500/50 bg-fuchsia-500/10',
    green: 'border-emerald-500/50 bg-emerald-500/10',
    yellow: 'border-amber-500/40 bg-amber-500/10',
    none: 'border-zinc-700 bg-zinc-800/40',
};
const TEXT_CLASS = {
    purple: 'text-fuchsia-300',
    green: 'text-emerald-300',
    yellow: 'text-amber-300',
    none: 'text-zinc-100',
};
const LAP_TEXT_CLASS = {
    purple: 'text-fuchsia-400',
    green: 'text-emerald-400',
    yellow: 'text-amber-400',
    none: 'text-white',
};

const sectorCellClass = (i) => CELL_CLASS[sectorState(i)];
const sectorTextClass = (i) => TEXT_CLASS[sectorState(i)];
const lapTextClass = computed(() => LAP_TEXT_CLASS[lapState.value]);

const formatMs = (ms) => (ms === null || ms === undefined ? '—' : formatLapTime(ms / 1000));
const formatSectorMs = (ms) => (ms === null || ms === undefined ? '—' : (ms / 1000).toFixed(3));

const lastLapDelta = computed(() =>
    formatDelta(telemetry.value.lastLap, telemetry.value.bestLap)
);

// ── Оборотомер + предавка ──────────────────────────────────────────────────
const REDLINE = 15000;
const revFraction = computed(() => Math.min(1, (telemetry.value.rpm ?? 0) / REDLINE));
const atRedline = computed(() => revFraction.value > 0.94);
const gearLabel = computed(() => (telemetry.value.gear === 0 ? 'R' : String(telemetry.value.gear ?? 1)));

// Сегменти на rev-бара с shift-lights: зелено → жълто → червено (последните мигат).
const revSegments = computed(() => {
    const total = 16;
    const filled = Math.round(revFraction.value * total);
    return Array.from({ length: total }, (_, i) => {
        let color = 'bg-emerald-500';
        if (i >= total - 3) {
            color = 'bg-red-500';
        } else if (i >= total - 7) {
            color = 'bg-amber-400';
        }
        return { on: i < filled, color };
    });
});

// Първо място в класацията на пистата (от всички потребители) → трофей.
const isFirstPlace = computed(() => resultMeta.value?.rank === 1);

/**
 * Three.js се зарежда динамично: ~600 KB, които нямат работа в основния
 * бъндъл на сайта, щом играта е една страница от двайсет.
 */
const startGame = async (track) => {
    loading.value = true;
    error.value = null;

    try {
        const [{ Game }, response] = await Promise.all([
            import('@/game/Game.js'),
            fetch(`/game-tracks/${track.slug}.json`),
        ]);

        if (!response.ok) {
            throw new Error(`Данните за пистата не се заредиха (${response.status}).`);
        }

        const data = await response.json();

        selectedTrack.value = track;
        result.value = null;
        resultMeta.value = null;
        preStart.value = true; // pre-start екран (избор трансмисия + управление) преди старта

        // Лилавите рекорди се теглят фоново — трябват чак на финала.
        fetchLeaderboard(track.slug);

        // Смяната на екрана рендерира canvas-а едва след цикъла на Vue —
        // без това `canvas.value` е още null.
        await nextTick();

        if (!canvas.value) {
            throw new Error('Платното не се инициализира.');
        }

        game.value = new Game(
            canvas.value,
            data,
            (values) => {
                telemetry.value = values;
            },
            onFinish
        );

        // Изчакай средата (HDRI + болид + текстури) да се зареди. НЕ стартираме
        // тук — стартът чака бутона „Карай" от pre-start екрана (beginLap), след
        // като играчът избере трансмисия. Ако играчът напусне през това време
        // (game.value става null/друга инстанция), не пипаме мъртвата инстанция.
        const instance = game.value;
        await instance.ready?.catch(() => {});
        if (game.value !== instance) {
            return;
        }
        window.addEventListener('resize', handleResize);
    } catch (e) {
        error.value = e.message ?? 'Нещо се обърка при зареждането.';
        selectedTrack.value = null;
    } finally {
        loading.value = false;
    }
};

// Пуска обиколката от pre-start екрана: прилага избраната трансмисия и стартира.
const beginLap = () => {
    if (!game.value) {
        return;
    }
    game.value.setTransmission(transmission.value);
    preStart.value = false;
    game.value.start();
};

const quit = () => {
    teardown();
    selectedTrack.value = null;
    preStart.value = false;
    telemetry.value = emptyTelemetry();
    result.value = null;
    resultMeta.value = null;
    leaderboard.value = [];
};

const restart = () => game.value?.reset(true);

const handleResize = () => game.value?.resize();

const teardown = () => {
    window.removeEventListener('resize', handleResize);
    game.value?.dispose();
    game.value = null;
};

// Без това всяка навигация из сайта оставя жив WebGL контекст — браузърите
// пазят шепа такива и после отказват да създават нови.
onBeforeUnmount(teardown);

// ── Управление от екрана (телефон) ────────────────────────────────────────
const setInput = (values) => game.value?.setTouchInput(values);

const holdSteer = (direction) => setInput({ steer: direction });
const releaseSteer = () => setInput({ steer: 0 });
const holdThrottle = () => setInput({ throttle: 1 });
const releaseThrottle = () => setInput({ throttle: 0 });
const holdBrake = () => setInput({ brake: 1 });
const releaseBrake = () => setInput({ brake: 0 });
</script>

<template>
    <PublicLayout>
        <Head title="Хронометър" />

        <!-- ── Избор на писта ─────────────────────────────────────────── -->
        <div v-if="!selectedTrack" class="mx-auto max-w-5xl px-4 py-10 sm:py-14">
            <div class="mb-8">
                <h1 class="text-3xl font-black tracking-tight text-zinc-100 sm:text-4xl">
                    Хронометър<span class="text-[#e10600]">.</span>
                </h1>
                <p class="mt-3 max-w-2xl text-zinc-400">
                    Избери писта и карай чиста обиколка. Трасетата са построени от
                    реалната геометрия на пистите — всеки завой е там, където му е мястото.
                </p>

            </div>

            <div
                v-if="error"
                class="mb-6 rounded-lg border border-red-900/50 bg-red-950/40 px-4 py-3 text-sm text-red-200"
            >
                {{ error }}
            </div>

            <div v-if="tracks.length === 0" class="rounded-lg border border-zinc-800 bg-zinc-900/50 px-4 py-8 text-center text-zinc-400">
                Няма генерирани писти. Пусни
                <code class="rounded bg-zinc-800 px-1.5 py-0.5 text-zinc-300">php artisan game:generate-tracks</code>.
            </div>

            <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <button
                    v-for="track in tracks"
                    :key="track.slug"
                    type="button"
                    :disabled="loading"
                    class="group relative overflow-hidden rounded-xl border border-zinc-800 bg-zinc-900/60 p-5 text-left transition hover:border-[#e10600]/60 hover:bg-zinc-900 disabled:cursor-wait disabled:opacity-50"
                    @click="startGame(track)"
                >
                    <div class="text-lg font-bold text-zinc-100 group-hover:text-white">
                        {{ track.name }}
                    </div>
                    <div class="mt-1 text-sm text-zinc-500">{{ track.location }}</div>
                    <div class="mt-4 flex items-baseline gap-4">
                        <div class="flex items-baseline gap-1.5">
                            <span class="font-mono text-2xl font-bold text-[#e10600]">
                                {{ (track.length / 1000).toFixed(3) }}
                            </span>
                            <span class="text-xs uppercase tracking-wider text-zinc-500">км</span>
                        </div>
                        <div v-if="track.elevation > 3" class="flex items-baseline gap-1.5">
                            <span class="font-mono text-lg font-semibold text-zinc-300">
                                {{ Math.round(track.elevation) }}
                            </span>
                            <span class="text-xs uppercase tracking-wider text-zinc-500">м денивелация</span>
                        </div>
                    </div>
                </button>
            </div>

            <p class="mt-8 text-sm text-zinc-500">
                Управление: <kbd class="rounded bg-zinc-800 px-1.5 py-0.5 text-zinc-300">↑</kbd>
                газ, <kbd class="rounded bg-zinc-800 px-1.5 py-0.5 text-zinc-300">↓</kbd> спирачка,
                <kbd class="rounded bg-zinc-800 px-1.5 py-0.5 text-zinc-300">←</kbd>
                <kbd class="rounded bg-zinc-800 px-1.5 py-0.5 text-zinc-300">→</kbd> завиване,
                <kbd class="rounded bg-zinc-800 px-1.5 py-0.5 text-zinc-300">R</kbd> рестарт.
                Трансмисията (авто/ръчна) избираш преди всяка обиколка.
            </p>

            <!--
                Атрибуцията не е учтивост: данните за трасетата са под ODbL и
                посочването на източника е условие за ползването им.
            -->
            <div class="mt-10 border-t border-zinc-800 pt-6 text-xs leading-relaxed text-zinc-600">
                <p>
                    Трасетата са изградени от свободни географски данни: очертания и
                    ориентири © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer" class="underline hover:text-zinc-400">OpenStreetMap contributors</a>
                    (ODbL), надморска височина през
                    <a href="https://www.opentopodata.org" target="_blank" rel="noopener noreferrer" class="underline hover:text-zinc-400">OpenTopoData</a>
                    (Mapzen / Copernicus).
                </p>
                <p class="mt-2">
                    Падок не е свързан с Formula One Group, FIA или отбор. Пистите са
                    възпроизведени по географски данни, без реклами, лога и ливреи.
                </p>
            </div>
        </div>

        <!-- ── Игрови екран ───────────────────────────────────────────── -->
        <div v-else class="relative">
            <div class="relative h-[calc(100vh-4rem)] min-h-[420px] w-full overflow-hidden bg-zinc-950">
                <canvas ref="canvas" class="block h-full w-full touch-none"></canvas>

                <!-- Тайминг -->
                <div class="pointer-events-none absolute left-0 top-0 p-4 sm:p-6">
                    <div class="rounded-lg bg-black/55 px-4 py-3 backdrop-blur-sm">
                        <div
                            class="text-[10px] font-semibold uppercase tracking-widest"
                            :class="telemetry.started ? 'text-zinc-400' : 'text-amber-400'"
                        >
                            {{ telemetry.started ? 'Квалификационна обиколка' : 'Загряваща обиколка' }}
                        </div>
                        <div
                            class="font-mono text-3xl font-bold tabular-nums sm:text-4xl"
                            :class="telemetry.gated ? 'text-amber-400' : 'text-white'"
                        >
                            {{ telemetry.started ? formatLapTime(telemetry.lapTime) : '--:--.---' }}
                        </div>
                        <div
                            v-if="telemetry.gated"
                            class="text-[10px] font-semibold uppercase tracking-wider text-amber-400"
                        >
                            Върни скоростта…
                        </div>

                        <div class="mt-2 space-y-0.5 text-xs">
                            <div class="flex justify-between gap-6">
                                <span class="text-zinc-400">Най-добра</span>
                                <span class="font-mono tabular-nums text-emerald-400">
                                    {{ formatLapTime(telemetry.bestLap) }}
                                </span>
                            </div>
                            <div class="flex justify-between gap-6">
                                <span class="text-zinc-400">Последна</span>
                                <span class="font-mono tabular-nums text-zinc-200">
                                    {{ formatLapTime(telemetry.lastLap) }}
                                    <span
                                        v-if="lastLapDelta"
                                        :class="telemetry.lastLap <= telemetry.bestLap ? 'text-emerald-400' : 'text-red-400'"
                                    >
                                        {{ lastLapDelta }}
                                    </span>
                                </span>
                            </div>
                        </div>

                        <div v-if="!telemetry.started" class="mt-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">
                            Мини старта за хронометрирана обиколка
                        </div>
                        <div v-else class="mt-2 flex items-center gap-1.5">
                            <span class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500">Излизания</span>
                            <span
                                v-for="w in telemetry.maxWarnings"
                                :key="w"
                                class="h-2 w-2 rounded-full"
                                :class="telemetry.warnings >= w ? 'bg-red-500' : 'bg-zinc-600'"
                            ></span>
                        </div>
                    </div>

                    <div class="mt-2 flex gap-1">
                        <div
                            v-for="s in 3"
                            :key="s"
                            class="h-1 w-8 rounded-full"
                            :class="telemetry.started && telemetry.sector >= s ? 'bg-[#e10600]' : 'bg-zinc-700'"
                        ></div>
                    </div>

                    <!-- Секторни времена на последната обиколка -->
                    <div class="mt-1.5 flex gap-1.5 font-mono text-[10px] tabular-nums">
                        <span
                            v-for="(sec, i) in telemetry.sectors"
                            :key="i"
                            class="rounded bg-black/50 px-1.5 py-0.5 backdrop-blur-sm"
                        >
                            <span class="text-zinc-500">S{{ i + 1 }}</span>
                            <span class="ml-1 text-zinc-200">{{ sec === null ? '—' : sec.toFixed(3) }}</span>
                        </span>
                    </div>
                </div>

                <!-- ODbL иска източникът да се вижда там, където се вижда и картата. -->
                <div class="pointer-events-none absolute bottom-0 left-0 hidden p-2 text-[10px] text-white/35 sm:block">
                    Трасе © OpenStreetMap contributors · височини OpenTopoData
                </div>

                <!-- Скорост + предавка + оборотомер -->
                <div class="pointer-events-none absolute bottom-0 right-0 p-4 sm:p-6">
                    <div class="rounded-lg bg-black/55 px-5 py-3 backdrop-blur-sm">
                        <!-- Rev бар с shift-lights (мига в червената зона) -->
                        <div class="mb-2 flex justify-end gap-[3px]" :class="atRedline ? 'animate-pulse' : ''">
                            <span
                                v-for="(seg, i) in revSegments"
                                :key="i"
                                class="h-2 w-2 rounded-[2px]"
                                :class="seg.on ? seg.color : 'bg-zinc-700/60'"
                            ></span>
                        </div>

                        <div class="flex items-end justify-end gap-4">
                            <!-- Предавка -->
                            <div class="text-center">
                                <div class="font-mono text-4xl font-black leading-none tabular-nums text-white sm:text-5xl">
                                    {{ gearLabel }}
                                </div>
                                <div class="text-[9px] font-semibold uppercase tracking-widest text-zinc-500">
                                    предавка
                                </div>
                            </div>
                            <!-- Скорост -->
                            <div class="text-right">
                                <div class="font-mono text-5xl font-black leading-none tabular-nums text-white sm:text-6xl">
                                    {{ telemetry.speed }}
                                </div>
                                <div class="mt-1 text-[10px] font-semibold uppercase tracking-widest text-zinc-400">
                                    км/ч
                                </div>
                            </div>
                        </div>

                        <!-- Обороти -->
                        <div class="mt-1 text-right font-mono text-[11px] tabular-nums text-zinc-400">
                            {{ (telemetry.rpm ?? 0).toLocaleString('bg-BG') }}
                            <span class="text-zinc-600">об/мин</span>
                        </div>
                    </div>
                </div>

                <!-- Изход / рестарт -->
                <div class="absolute right-0 top-0 flex gap-2 p-4 sm:p-6">
                    <button
                        type="button"
                        class="rounded-lg bg-black/55 px-3 py-2 text-xs font-semibold uppercase tracking-wider text-zinc-300 backdrop-blur-sm transition hover:bg-black/75 hover:text-white"
                        @click="restart"
                    >
                        Рестарт
                    </button>
                    <button
                        type="button"
                        class="rounded-lg bg-black/55 px-3 py-2 text-xs font-semibold uppercase tracking-wider text-zinc-300 backdrop-blur-sm transition hover:bg-black/75 hover:text-white"
                        @click="quit"
                    >
                        Смени пистата
                    </button>
                </div>

                <!-- Управление на телефон: скрито на десктоп, където има клавиатура. -->
                <div class="absolute inset-x-0 bottom-0 flex select-none items-end justify-between p-4 sm:hidden">
                    <div class="flex gap-3">
                        <button
                            type="button"
                            class="h-16 w-16 rounded-full bg-white/10 text-2xl text-white backdrop-blur-sm active:bg-white/25"
                            @pointerdown.prevent="holdSteer(-1)"
                            @pointerup="releaseSteer"
                            @pointerleave="releaseSteer"
                            @pointercancel="releaseSteer"
                        >
                            ←
                        </button>
                        <button
                            type="button"
                            class="h-16 w-16 rounded-full bg-white/10 text-2xl text-white backdrop-blur-sm active:bg-white/25"
                            @pointerdown.prevent="holdSteer(1)"
                            @pointerup="releaseSteer"
                            @pointerleave="releaseSteer"
                            @pointercancel="releaseSteer"
                        >
                            →
                        </button>
                    </div>

                    <div class="flex gap-3">
                        <button
                            type="button"
                            class="h-16 w-16 rounded-full bg-white/10 text-xs font-bold uppercase text-white backdrop-blur-sm active:bg-white/25"
                            @pointerdown.prevent="holdBrake"
                            @pointerup="releaseBrake"
                            @pointerleave="releaseBrake"
                            @pointercancel="releaseBrake"
                        >
                            Спирачка
                        </button>
                        <button
                            type="button"
                            class="h-16 w-16 rounded-full bg-[#e10600]/80 text-xs font-bold uppercase text-white backdrop-blur-sm active:bg-[#e10600]"
                            @pointerdown.prevent="holdThrottle"
                            @pointerup="releaseThrottle"
                            @pointerleave="releaseThrottle"
                            @pointercancel="releaseThrottle"
                        >
                            Газ
                        </button>
                    </div>
                </div>

                <!-- ── Преди старта: избор на трансмисия + управление ────── -->
                <div
                    v-if="preStart"
                    class="absolute inset-0 z-30 flex items-center justify-center bg-black/80 p-4 backdrop-blur-sm"
                >
                    <div class="w-full max-w-md rounded-xl border border-zinc-800 bg-zinc-900/95 p-6 shadow-2xl">
                        <h2 class="text-center text-lg font-black uppercase tracking-wider text-zinc-100">
                            {{ selectedTrack?.name }}
                        </h2>
                        <p class="mt-1 text-center text-xs text-zinc-500">
                            Готви се за квалификационна обиколка
                        </p>

                        <div class="mt-5">
                            <div class="mb-2 text-[11px] font-semibold uppercase tracking-widest text-zinc-500">
                                Трансмисия
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <button
                                    v-for="opt in [
                                        { v: 'auto', l: 'Автоматична', h: 'Играта сменя предавките' },
                                        { v: 'manual', l: 'Ръчна', h: 'W нагоре · S надолу' },
                                    ]"
                                    :key="opt.v"
                                    type="button"
                                    class="rounded-lg border p-3 text-left transition"
                                    :class="transmission === opt.v ? 'border-[#e10600] bg-[#e10600]/10' : 'border-zinc-700 hover:border-zinc-500'"
                                    @click="transmission = opt.v"
                                >
                                    <div class="text-sm font-bold text-zinc-100">{{ opt.l }}</div>
                                    <div class="mt-0.5 text-[11px] text-zinc-400">{{ opt.h }}</div>
                                </button>
                            </div>
                        </div>

                        <div class="mt-4 rounded-lg bg-black/40 p-3">
                            <div class="mb-1.5 text-[10px] font-semibold uppercase tracking-widest text-zinc-500">
                                Управление
                            </div>
                            <div class="flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-zinc-300">
                                <span><kbd class="rounded bg-zinc-800 px-1.5 py-0.5">↑</kbd> газ</span>
                                <span><kbd class="rounded bg-zinc-800 px-1.5 py-0.5">↓</kbd> спирачка</span>
                                <span><kbd class="rounded bg-zinc-800 px-1.5 py-0.5">←</kbd> <kbd class="rounded bg-zinc-800 px-1.5 py-0.5">→</kbd> завиване</span>
                                <span v-if="transmission === 'manual'" class="font-semibold text-amber-300">
                                    <kbd class="rounded bg-zinc-800 px-1.5 py-0.5">W</kbd> нагоре ·
                                    <kbd class="rounded bg-zinc-800 px-1.5 py-0.5">S</kbd> надолу
                                </span>
                                <span><kbd class="rounded bg-zinc-800 px-1.5 py-0.5">R</kbd> рестарт</span>
                            </div>
                            <p class="mt-2 text-[11px] text-zinc-500">
                                Мини стартовата линия, за да пуснеш хронометъра.
                            </p>
                        </div>

                        <button
                            type="button"
                            class="mt-5 w-full rounded-lg bg-[#e10600] px-4 py-3 text-sm font-bold uppercase tracking-wider text-white transition hover:bg-[#ff0800] disabled:cursor-wait disabled:opacity-60"
                            :disabled="loading"
                            @click="beginLap"
                        >
                            {{ loading ? 'Зареждане…' : 'Карай' }}
                        </button>
                    </div>
                </div>

                <!-- ── Връщане на пистата: брояч 3-2-1 ──────────────────── -->
                <div
                    v-if="telemetry.recovering"
                    class="pointer-events-none absolute inset-0 z-30 flex flex-col items-center justify-center gap-3 bg-black/45"
                >
                    <div class="text-xs font-bold uppercase tracking-[0.3em] text-amber-300">
                        {{ telemetry.recoverRestart ? 'Времето изтрито · нова обиколка' : 'Връщане на пистата' }}
                    </div>
                    <div class="font-mono text-8xl font-black tabular-nums text-white drop-shadow-[0_2px_12px_rgba(0,0,0,0.9)]">
                        {{ telemetry.recoverCount }}
                    </div>
                </div>

                <!-- ── Резултат: кариран флаг + времена + класация ─────── -->
                <div
                    v-if="result"
                    class="absolute inset-0 z-20 flex items-center justify-center overflow-y-auto bg-black/75 p-4 backdrop-blur-sm"
                >
                    <div class="w-full max-w-md rounded-xl border border-zinc-800 bg-zinc-900/95 p-5 shadow-2xl sm:p-6">
                        <!-- Кариран флаг -->
                        <div class="mb-4 overflow-hidden rounded">
                            <svg viewBox="0 0 120 8" preserveAspectRatio="none" class="h-2 w-full">
                                <defs>
                                    <pattern id="chequer" width="8" height="8" patternUnits="userSpaceOnUse">
                                        <rect width="8" height="8" fill="#fafafa" />
                                        <rect width="4" height="4" fill="#0a0a0a" />
                                        <rect x="4" y="4" width="4" height="4" fill="#0a0a0a" />
                                    </pattern>
                                </defs>
                                <rect width="120" height="8" fill="url(#chequer)" />
                            </svg>
                        </div>

                        <!-- Първо място в класацията на пистата → трофей -->
                        <div v-if="isFirstPlace" class="mb-4 flex flex-col items-center">
                            <img
                                src="/game-textures/trophy/trophy.png"
                                alt="Трофей за първо място"
                                class="h-28 w-auto drop-shadow-[0_8px_22px_rgba(234,179,8,0.5)]"
                            />
                            <div class="mt-1 text-base font-black uppercase tracking-[0.2em] text-amber-400">
                                Първо място!
                            </div>
                            <div class="text-[11px] text-zinc-400">
                                Най-бързата обиколка на пистата — от всички
                            </div>
                        </div>

                        <div class="mb-1 flex items-center justify-center gap-2">
                            <span class="text-2xl">🏁</span>
                            <h2 class="text-lg font-black uppercase tracking-wider text-zinc-100">
                                {{ result.valid ? 'Финал' : 'Край на обиколката' }}
                            </h2>
                        </div>
                        <p class="mb-4 text-center text-xs text-zinc-500">
                            {{ selectedTrack?.name }} · квалификационна обиколка
                        </p>

                        <!-- Време на обиколката -->
                        <div class="text-center">
                            <div
                                class="font-mono text-4xl font-black tabular-nums sm:text-5xl"
                                :class="lapTextClass"
                            >
                                {{ formatMs(result.lapMs) }}
                            </div>
                            <div
                                v-if="bests.lap_ms !== null"
                                class="mt-1 font-mono text-xs tabular-nums text-fuchsia-400/70"
                            >
                                Рекорд на пистата: {{ formatMs(bests.lap_ms) }}
                            </div>
                        </div>

                        <!-- Сектори -->
                        <div class="mt-4 grid grid-cols-3 gap-2">
                            <div
                                v-for="(sec, i) in result.sectorsMs"
                                :key="i"
                                class="rounded-lg border p-2 text-center"
                                :class="sectorCellClass(i)"
                            >
                                <div class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500">
                                    Сектор {{ i + 1 }}
                                </div>
                                <div
                                    class="font-mono text-base font-bold tabular-nums"
                                    :class="sectorTextClass(i)"
                                >
                                    {{ formatSectorMs(sec) }}
                                </div>
                                <div
                                    v-if="bests.sectors_ms[i] !== null"
                                    class="mt-0.5 font-mono text-[10px] tabular-nums text-fuchsia-400/70"
                                >
                                    {{ formatSectorMs(bests.sectors_ms[i]) }}
                                </div>
                            </div>
                        </div>

                        <!-- Значки: позиция / рекорд -->
                        <div
                            v-if="resultMeta"
                            class="mt-3 flex flex-wrap items-center justify-center gap-2 text-xs"
                        >
                            <span class="rounded-full bg-zinc-800 px-2.5 py-1 font-semibold text-zinc-200">
                                Позиция #{{ resultMeta.rank }}
                            </span>
                            <span
                                v-if="resultMeta.purple_lap"
                                class="rounded-full bg-fuchsia-500/20 px-2.5 py-1 font-semibold text-fuchsia-300"
                            >
                                Рекорд на пистата!
                            </span>
                            <span
                                v-else-if="resultMeta.personal_best"
                                class="rounded-full bg-emerald-500/20 px-2.5 py-1 font-semibold text-emerald-300"
                            >
                                Личен рекорд!
                            </span>
                        </div>

                        <!-- Статус на записа -->
                        <div v-if="submitting" class="mt-3 text-center text-xs text-zinc-400">
                            Записване…
                        </div>
                        <div v-if="submitError" class="mt-3 text-center text-xs text-red-400">
                            {{ submitError }}
                        </div>
                        <div
                            v-if="!result.valid"
                            class="mt-3 rounded-lg border border-amber-900/50 bg-amber-950/30 px-3 py-2 text-center text-xs text-amber-300"
                        >
                            Невалидна обиколка (излизане извън трасето) — не влиза в класацията.
                        </div>
                        <div
                            v-else-if="!authUser"
                            class="mt-3 rounded-lg border border-zinc-700 bg-zinc-800/40 px-3 py-2 text-center text-xs text-zinc-300"
                        >
                            <a href="/login" class="font-semibold text-[#e10600] hover:underline">Влез</a>,
                            за да запишеш времето си в класацията.
                        </div>

                        <!-- Класация -->
                        <div v-if="leaderboard.length" class="mt-4 border-t border-zinc-800 pt-3">
                            <div class="mb-1.5 text-[10px] font-semibold uppercase tracking-widest text-zinc-500">
                                Класация
                            </div>
                            <ol class="space-y-1">
                                <li
                                    v-for="(row, idx) in leaderboard"
                                    :key="idx"
                                    class="flex items-baseline justify-between gap-4 text-sm"
                                    :class="row.is_you ? 'text-fuchsia-300' : 'text-zinc-300'"
                                >
                                    <span class="truncate">
                                        <span class="tabular-nums text-zinc-500">{{ idx + 1 }}.</span>
                                        {{ row.name }}
                                    </span>
                                    <span class="font-mono tabular-nums">{{ formatMs(row.lap_ms) }}</span>
                                </li>
                            </ol>
                        </div>

                        <!-- Действия -->
                        <div class="mt-5 flex gap-2">
                            <button
                                type="button"
                                class="flex-1 rounded-lg bg-[#e10600] px-4 py-2.5 text-sm font-bold uppercase tracking-wider text-white transition hover:bg-[#ff0800]"
                                @click="newLap"
                            >
                                Нова обиколка
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border border-zinc-700 px-4 py-2.5 text-sm font-semibold text-zinc-300 transition hover:bg-zinc-800"
                                @click="quit"
                            >
                                Смени пистата
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </PublicLayout>
</template>
