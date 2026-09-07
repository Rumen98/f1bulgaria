<script setup>
import { bg, plot, points as polyPoints, round, scale, ticks } from '@/utils/chart';
import { tyre, tyreHex } from '@/utils/tyres';
import { computed } from 'vue';

/**
 * Как се влошава времето с възрастта на гумата.
 *
 * Оста X е възрастта на гумата, не номерът на обиколката — така стинтовете от
 * различни моменти на състезанието лягат един върху друг и съставите стават
 * сравними. Точките са отделните обиколки, дебелата линия е медианата.
 *
 * Времената са коригирани за гориво. Това не е козметика: болидът олеква ~1,6 кг
 * на обиколка и без корекцията кривата тръгва НАДОЛУ, все едно гумата се
 * подобрява с износването.
 */
const props = defineProps({
    compounds: { type: Array, required: true },
});

const box = plot(800, 320, { left: 46, right: 14, top: 14, bottom: 30 });

const allPoints = computed(() => props.compounds.flatMap((c) => c.points));

const maxAge = computed(() => Math.max(1, ...allPoints.value.map((p) => p[0])));
const times = computed(() => allPoints.value.map((p) => p[1]));

/**
 * Границите се режат по персентил, не по крайности: една обиколка зад
 * по-бавен болид е с 5 секунди по-бавна и сама по себе си смачква цялата
 * графика в долната ѝ трета.
 */
const bounds = computed(() => {
    const sorted = [...times.value].sort((a, b) => a - b);
    const at = (p) => sorted[Math.min(sorted.length - 1, Math.floor(sorted.length * p))];
    const low = at(0.02);
    const high = at(0.9);
    const pad = Math.max(0.3, (high - low) * 0.1);

    return { min: low - pad, max: high + pad };
});

const x = computed(() => scale(0, maxAge.value, box.left, box.left + box.innerWidth));
// ОБЪРНАТА ос спрямо останалите графики: тук по-голямото време е нагоре.
// Причината е, че това е графика на ЗАГУБА — изкачваща се крива се чете като
// „влошава се“. При обичайната посока (бързото горе) деградацията върви надолу
// и изглежда като подобрение.
const y = computed(() => scale(bounds.value.min, bounds.value.max, box.top + box.innerHeight, box.top));

const yLines = computed(() => ticks(bounds.value.min, bounds.value.max, 4));
const xLines = computed(() => ticks(0, maxAge.value, 5).filter((t) => t > 0));

/** Точките извън среза не се рисуват — иначе висят по ръба като артефакт. */
const visible = (point) => point[1] >= bounds.value.min && point[1] <= bounds.value.max;

const median = (compound) => polyPoints(compound.median.filter(visible), x.value, y.value);

const legend = computed(() =>
    props.compounds.map((c) => ({
        key: c.compound,
        label: tyre(c.compound)?.label ?? c.compound,
        hex: tyreHex(c.compound),
        // Разликата между първата и последната медиана е самата деградация.
        loss: c.median.length >= 2 ? c.median[c.median.length - 1][1] - c.median[0][1] : null,
    })),
);
</script>

<template>
    <div>
        <svg :viewBox="`0 0 ${box.width} ${box.height}`" class="h-auto w-full" role="img" aria-label="Деградация на гумите по състав">
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
                {{ bg(tick, 1) }}
            </text>

            <g v-for="compound in compounds" :key="compound.compound">
                <circle
                    v-for="(point, i) in compound.points.filter(visible)"
                    :key="i"
                    :cx="round(x(point[0]))"
                    :cy="round(y(point[1]))"
                    r="1.8"
                    :fill="tyreHex(compound.compound)"
                    opacity="0.28"
                />
                <polyline
                    :points="median(compound)"
                    fill="none"
                    :stroke="tyreHex(compound.compound)"
                    stroke-width="2.5"
                    stroke-linejoin="round"
                    stroke-linecap="round"
                    vector-effect="non-scaling-stroke"
                />
            </g>

            <text
                v-for="tick in xLines"
                :key="`x-${tick}`"
                :x="round(x(tick))"
                :y="box.height - 10"
                text-anchor="middle"
                fill="#83838d"
                font-size="11"
                style="font-variant-numeric: tabular-nums"
            >
                {{ Math.round(tick) }}
            </text>
        </svg>

        <p class="mt-1 text-center text-[11px] uppercase tracking-wide text-zinc-600">Възраст на гумата, обиколки</p>

        <div class="mt-3 flex flex-wrap gap-x-5 gap-y-2 border-t border-zinc-800 pt-3 text-xs text-zinc-400">
            <span v-for="item in legend" :key="item.key" class="inline-flex items-center gap-1.5">
                <i class="inline-block h-0.5 w-4" :style="{ backgroundColor: item.hex }" aria-hidden="true" />
                {{ item.label }}
                <span v-if="item.loss !== null" class="font-mono tabular-nums" :class="item.loss > 0 ? 'text-zinc-300' : 'text-emerald-400'">
                    {{ item.loss > 0 ? '+' : '' }}{{ bg(item.loss, 2) }} с
                </span>
            </span>
            <span class="text-zinc-600">Времената са коригирани за изразходено гориво.</span>
        </div>
    </div>
</template>
