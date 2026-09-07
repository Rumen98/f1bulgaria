<script setup>
import { bg, plot, points, round, scale, ticks } from '@/utils/chart';
import { computed, ref } from 'vue';

/**
 * Линейна графика по обиколки — ползва се и за позициите, и за изоставането
 * от лидера. Двете се различават само по оста Y, затова са един компонент.
 *
 * Ръчен SVG по причина, не по инат: в проекта няма библиотека за графики,
 * страницата се рендира и на сървъра (SSR), а десет полилинии с оси не
 * оправдават нова зависимост. Всичко тук е чисти сметки — нула достъп до
 * window или document.
 */
const props = defineProps({
    series: { type: Array, required: true },
    yUnit: { type: String, default: '' },
    /** Колко деления по оста Y. Позициите понасят по-малко от секундите. */
    yTicks: { type: Number, default: 5 },
    /** Прозорците на неутрализация като [{from, to}] в обиколки. */
    bands: { type: Array, default: () => [] },
    /** Колко знака след запетаята по оста Y. */
    digits: { type: Number, default: 0 },
});

const box = plot(800, 300);

/** Кой пилот е откроен в момента; null = всички са равни. */
const focused = ref(null);

const xs = computed(() => props.series.flatMap((s) => s.points.map(([x]) => x)));
const ys = computed(() => props.series.flatMap((s) => s.points.map(([, y]) => y)));

const xMin = computed(() => (xs.value.length ? Math.min(...xs.value) : 0));
const xMax = computed(() => (xs.value.length ? Math.max(...xs.value) : 1));
const yMin = computed(() => (ys.value.length ? Math.min(...ys.value) : 0));
const yMax = computed(() => (ys.value.length ? Math.max(...ys.value) : 1));

const x = computed(() => scale(xMin.value, xMax.value, box.left, box.left + box.innerWidth));

// По-малката стойност е нагоре — и за двете графики това е правилната посока:
// позиция 1 е най-доброто място, изоставане 0 е лидерът.
const y = computed(() => scale(yMin.value, yMax.value, box.top, box.top + box.innerHeight));

const yLines = computed(() => ticks(yMin.value, yMax.value, props.yTicks));
const xTicks = computed(() => ticks(xMin.value, xMax.value, 5).filter((t) => t >= xMin.value && t <= xMax.value));

const line = (serie) => points(serie.points, x.value, y.value);

const bandRects = computed(() =>
    props.bands.map((band) => {
        const from = x.value(Math.max(band.from, xMin.value));
        const to = x.value(Math.min(band.to, xMax.value));

        return { x: round(from), width: round(Math.max(1, to - from)) };
    }),
);

const opacity = (serie) => {
    if (focused.value === null) {
        return 0.85;
    }

    return focused.value === serie.number ? 1 : 0.15;
};

const label = (value) => (props.digits > 0 ? bg(value, props.digits) : String(Math.round(value)));

const focus = (number) => {
    focused.value = focused.value === number ? null : number;
};
</script>

<template>
    <div>
        <svg
            :viewBox="`0 0 ${box.width} ${box.height}`"
            class="h-auto w-full"
            role="img"
            :aria-label="`Графика по обиколки за ${series.length} пилота`"
        >
            <!-- Прозорците на неутрализация са фон: те обясняват защо линиите
                 се събират, но не са данни за пилот. -->
            <rect
                v-for="(band, i) in bandRects"
                :key="`b-${i}`"
                :x="band.x"
                :y="box.top"
                :width="band.width"
                :height="box.innerHeight"
                fill="#fbbf24"
                opacity="0.07"
            />

            <g>
                <line
                    v-for="tick in yLines"
                    :key="`gy-${tick}`"
                    :x1="box.left"
                    :x2="box.left + box.innerWidth"
                    :y1="round(y(tick))"
                    :y2="round(y(tick))"
                    stroke="#27272a"
                    stroke-width="1"
                    vector-effect="non-scaling-stroke"
                />
                <text
                    v-for="tick in yLines"
                    :key="`ly-${tick}`"
                    :x="box.left - 8"
                    :y="round(y(tick)) + 4"
                    text-anchor="end"
                    fill="#83838d"
                    font-size="11"
                    style="font-variant-numeric: tabular-nums"
                >
                    {{ label(tick) }}{{ yUnit }}
                </text>
            </g>

            <g>
                <text
                    v-for="tick in xTicks"
                    :key="`lx-${tick}`"
                    :x="round(x(tick))"
                    :y="box.height - 8"
                    text-anchor="middle"
                    fill="#83838d"
                    font-size="11"
                    style="font-variant-numeric: tabular-nums"
                >
                    {{ Math.round(tick) }}
                </text>
            </g>

            <polyline
                v-for="serie in series"
                :key="serie.number"
                :points="line(serie)"
                fill="none"
                :stroke="serie.colour"
                stroke-width="2"
                stroke-linejoin="round"
                stroke-linecap="round"
                vector-effect="non-scaling-stroke"
                :stroke-dasharray="serie.dashed ? '7 4' : undefined"
                :opacity="opacity(serie)"
                class="transition-opacity duration-200"
            />
        </svg>

        <p class="mt-1 text-center text-[11px] uppercase tracking-wide text-zinc-600">Обиколка</p>

        <!-- Легендата е и управлението: докосване или клик откроява един пилот.
             Работи и с пръст, за разлика от hover върху самата линия. -->
        <div class="mt-3 flex flex-wrap gap-x-3 gap-y-2 border-t border-zinc-800 pt-3">
            <button
                v-for="serie in series"
                :key="serie.number"
                type="button"
                class="inline-flex items-center gap-1.5 rounded px-1.5 py-0.5 font-mono text-[11px] font-semibold transition"
                :class="focused === null || focused === serie.number ? 'text-zinc-200' : 'text-zinc-600'"
                :aria-pressed="focused === serie.number"
                @click="focus(serie.number)"
            >
                <!-- Пунктираният квадрат повтаря стила на линията, за да е
                     ясно кой от двамата съотборници е кой. -->
                <i
                    class="inline-block h-2.5 w-2.5 rounded-sm"
                    :class="serie.dashed ? 'border border-dashed bg-transparent' : ''"
                    :style="serie.dashed ? { borderColor: serie.colour } : { backgroundColor: serie.colour }"
                    aria-hidden="true"
                />
                {{ serie.short }}
            </button>
        </div>
    </div>
</template>
