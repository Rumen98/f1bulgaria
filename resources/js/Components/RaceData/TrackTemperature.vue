<script setup>
// polyPoints, а не points: пропът също се казва points и в шаблона печели той.
import { bg, plot, points as polyPoints, round, scale, ticks } from '@/utils/chart';
import { computed, useId } from 'vue';

/**
 * Температурата на асфалта през сесията.
 *
 * OpenF1 пише по един запис в минута и покрива и часа преди старта, така че
 * се вижда как пистата се загрява и после охлажда — това е контекстът за
 * деградацията на гумите в лентите отгоре.
 *
 * useId() за градиента, а не Math.random(): страницата се рендира и на
 * сървъра, а несъвпадащ id чупи хидратацията.
 */
const props = defineProps({
    /** [[минута, асфалт, въздух|null, дъжд 0|1], ...] */
    points: { type: Array, required: true },
});

const gradientId = `track-temp-${useId()}`;
const box = plot(800, 160, { left: 42, bottom: 24 });

const track = computed(() => props.points.map((p) => [p[0], p[1]]));
const air = computed(() => props.points.filter((p) => Number.isFinite(p[2])).map((p) => [p[0], p[2]]));

const all = computed(() => [...track.value.map((p) => p[1]), ...air.value.map((p) => p[1])]);

const xMin = computed(() => Math.min(...track.value.map((p) => p[0])));
const xMax = computed(() => Math.max(...track.value.map((p) => p[0])));
// Малко въздух отгоре и отдолу, за да не лепне линията за рамката.
const yMin = computed(() => Math.floor(Math.min(...all.value) - 1));
const yMax = computed(() => Math.ceil(Math.max(...all.value) + 1));

const x = computed(() => scale(xMin.value, xMax.value, box.left, box.left + box.innerWidth));
const y = computed(() => scale(yMax.value, yMin.value, box.top, box.top + box.innerHeight));

const yLines = computed(() => ticks(yMin.value, yMax.value, 3));

const trackLine = computed(() => polyPoints(track.value, x.value, y.value));
const airLine = computed(() => polyPoints(air.value, x.value, y.value));

/** Затворен контур за градиента под линията на асфалта. */
const area = computed(() => {
    if (track.value.length === 0) {
        return '';
    }

    const baseline = round(box.top + box.innerHeight);
    const first = round(x.value(track.value[0][0]));
    const last = round(x.value(track.value[track.value.length - 1][0]));

    return `${first},${baseline} ${trackLine.value} ${last},${baseline}`;
});

const rain = computed(() => props.points.some((p) => p[3] === 1));
</script>

<template>
    <div>
        <svg :viewBox="`0 0 ${box.width} ${box.height}`" class="h-auto w-full" role="img" aria-label="Температура на асфалта през сесията">
            <defs>
                <linearGradient :id="gradientId" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="#e10600" stop-opacity="0.28" />
                    <stop offset="100%" stop-color="#e10600" stop-opacity="0" />
                </linearGradient>
            </defs>

            <line
                v-for="tick in yLines"
                :key="`g-${tick}`"
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
                :key="`t-${tick}`"
                :x="box.left - 8"
                :y="round(y(tick)) + 4"
                text-anchor="end"
                fill="#83838d"
                font-size="11"
                style="font-variant-numeric: tabular-nums"
            >
                {{ Math.round(tick) }}°
            </text>

            <polygon :points="area" :fill="`url(#${gradientId})`" />
            <polyline :points="trackLine" fill="none" stroke="#e10600" stroke-width="2" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
            <polyline
                v-if="airLine"
                :points="airLine"
                fill="none"
                stroke="#38bdf8"
                stroke-width="1.5"
                stroke-dasharray="4 4"
                stroke-linejoin="round"
                vector-effect="non-scaling-stroke"
            />
        </svg>

        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-zinc-400">
            <span class="inline-flex items-center gap-1.5">
                <i class="inline-block h-0.5 w-4 bg-red-600" aria-hidden="true" />
                Асфалт {{ bg(Math.min(...track.map((p) => p[1]))) }}–{{ bg(Math.max(...track.map((p) => p[1]))) }}°
            </span>
            <span v-if="air.length" class="inline-flex items-center gap-1.5">
                <i class="inline-block h-0.5 w-4 bg-sky-400" aria-hidden="true" />
                Въздух
            </span>
            <span v-if="rain" class="text-sky-300">Имало е дъжд по трасето</span>
        </div>
    </div>
</template>
