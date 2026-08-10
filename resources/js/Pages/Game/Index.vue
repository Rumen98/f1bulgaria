<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { formatDelta, formatLapTime } from '@/game/format.js';
import { Head } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref, shallowRef } from 'vue';

const props = defineProps({
    tracks: { type: Array, default: () => [] },
});

const canvas = ref(null);
const game = shallowRef(null);
const selectedTrack = ref(null);
const loading = ref(false);
const error = ref(null);

const telemetry = ref({
    speed: 0,
    lapTime: null,
    lastLap: null,
    bestLap: null,
    sector: 1,
    lapValid: true,
    started: false,
});

const lastLapDelta = computed(() =>
    formatDelta(telemetry.value.lastLap, telemetry.value.bestLap)
);

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
            fetch(`/game/tracks/${track.slug}.json`),
        ]);

        if (!response.ok) {
            throw new Error(`Данните за пистата не се заредиха (${response.status}).`);
        }

        const data = await response.json();

        selectedTrack.value = track;

        // Смяната на екрана рендерира canvas-а едва след цикъла на Vue —
        // без това `canvas.value` е още null.
        await nextTick();

        if (!canvas.value) {
            throw new Error('Платното не се инициализира.');
        }

        game.value = new Game(canvas.value, data, (values) => {
            telemetry.value = values;
        });
        game.value.start();

        window.addEventListener('resize', handleResize);
    } catch (e) {
        error.value = e.message ?? 'Нещо се обърка при зареждането.';
        selectedTrack.value = null;
    } finally {
        loading.value = false;
    }
};

const quit = () => {
    teardown();
    selectedTrack.value = null;
    telemetry.value = {
        speed: 0,
        lapTime: null,
        lastLap: null,
        bestLap: null,
        sector: 1,
        lapValid: true,
        started: false,
    };
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
                        <div class="text-[10px] font-semibold uppercase tracking-widest text-zinc-400">
                            Обиколка
                        </div>
                        <div
                            class="font-mono text-3xl font-bold tabular-nums sm:text-4xl"
                            :class="telemetry.lapValid ? 'text-white' : 'text-amber-400'"
                        >
                            {{ telemetry.started ? formatLapTime(telemetry.lapTime) : '--:--.---' }}
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

                        <div v-if="!telemetry.lapValid" class="mt-2 text-[10px] font-semibold uppercase tracking-wider text-amber-400">
                            Извън трасето
                        </div>
                        <div v-else-if="!telemetry.started" class="mt-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">
                            Мини стартовата линия
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
                </div>

                <!-- ODbL иска източникът да се вижда там, където се вижда и картата. -->
                <div class="pointer-events-none absolute bottom-0 left-0 hidden p-2 text-[10px] text-white/35 sm:block">
                    Трасе © OpenStreetMap contributors · височини OpenTopoData
                </div>

                <!-- Скорост -->
                <div class="pointer-events-none absolute bottom-0 right-0 p-4 sm:p-6">
                    <div class="rounded-lg bg-black/55 px-5 py-3 text-right backdrop-blur-sm">
                        <div class="font-mono text-5xl font-black leading-none tabular-nums text-white sm:text-6xl">
                            {{ telemetry.speed }}
                        </div>
                        <div class="mt-1 text-[10px] font-semibold uppercase tracking-widest text-zinc-400">
                            км/ч
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
            </div>
        </div>
    </PublicLayout>
</template>
