<script setup>
import { useChartBox } from '@/composables/useChartBox';
import { baselineShift, bg, points as polyPoints, round, scale, ticks } from '@/utils/chart';
import { computed } from 'vue';

/**
 * Телеметрията на една обиколка: скорост, газ и предавка по изминатата
 * дистанция — графиката, която всички познават от телевизията.
 *
 * Три панела с ОБЩА ос по дистанция, за да може окото да пада вертикално:
 * „тук вдига крак, тук спира, тук сваля две предавки". Отделни графики с
 * различни оси щяха да убият точно това.
 *
 * Спирачката е лента отдолу, а не линия: полето е двоично (натисната или не),
 * а линия между 0 и 1 създава впечатление за нещо непрекъснато.
 */
const props = defineProps({
    series: { type: Array, required: true },
});

const { host, box, isNarrow, fontSize, tickCount } = useChartBox(
    { width: 800, height: 420, padding: { left: 44, right: 14, top: 14, bottom: 28 } },
    // Отгоре трябва място за надписа на първия панел, а той расте с шрифта.
    { width: 440, height: 420, padding: { left: 48, right: 12, top: 18, bottom: 30 } },
);

/** Надписите на панелите стоят под тези на осите — както в телевизионния HUD. */
const captionSize = computed(() => Math.round(fontSize.value * 0.9));

// Три панела: скорост (най-висок, там е информацията), газ, предавка.
const panels = computed(() => {
    // Разстоянието между панелите носи надписа на долния — при едрия шрифт
    // трябва да е по-голямо, иначе буквите лягат върху горната графика.
    const gap = isNarrow.value ? 20 : 14;
    const usable = box.value.innerHeight - gap * 2;
    const speed = Math.round(usable * 0.5);
    const throttle = Math.round(usable * 0.28);
    const gear = usable - speed - throttle;

    return {
        speed: { top: box.value.top, height: speed },
        throttle: { top: box.value.top + speed + gap, height: throttle },
        gear: { top: box.value.top + speed + gap + throttle + gap, height: gear },
    };
});

const all = computed(() => props.series.flatMap((s) => s.points));

const maxDistance = computed(() => Math.max(1, ...all.value.map((p) => p[0])));
const maxSpeed = computed(() => Math.max(...all.value.map((p) => p[1])));
const minSpeed = computed(() => Math.min(...all.value.map((p) => p[1])));
const maxGear = computed(() => Math.max(1, ...all.value.map((p) => p[4])));

const x = computed(() => scale(0, maxDistance.value, box.value.left, box.value.left + box.value.innerWidth));

const ySpeed = computed(() =>
    scale(minSpeed.value, maxSpeed.value, panels.value.speed.top + panels.value.speed.height, panels.value.speed.top),
);
const yThrottle = computed(() =>
    scale(0, 100, panels.value.throttle.top + panels.value.throttle.height, panels.value.throttle.top),
);
const yGear = computed(() =>
    scale(0, maxGear.value, panels.value.gear.top + panels.value.gear.height, panels.value.gear.top),
);

const speedTicks = computed(() => ticks(minSpeed.value, maxSpeed.value, Math.min(3, tickCount.value)));
const distanceTicks = computed(() =>
    ticks(0, maxDistance.value, tickCount.value).filter((t) => t > 0 && t < maxDistance.value),
);

const speedLine = (serie) => polyPoints(serie.points.map((p) => [p[0], p[1]]), x.value, ySpeed.value);
const throttleLine = (serie) => polyPoints(serie.points.map((p) => [p[0], p[2]]), x.value, yThrottle.value);

/** Предавката е стъпало, не наклон — между 4-та и 5-а няма 4,5. */
const gearSteps = (serie) => {
    const out = [];
    let previous = null;

    for (const point of serie.points) {
        const px = round(x.value(point[0]));
        const py = round(yGear.value(point[4]));

        if (previous !== null && previous !== py) {
            out.push(`${px},${previous}`);
        }

        out.push(`${px},${py}`);
        previous = py;
    }

    return out.join(' ');
};

/** Отсечките, в които спирачката е натисната. */
const brakeZones = (serie) => {
    const zones = [];
    let start = null;

    for (const point of serie.points) {
        if (point[3] === 1 && start === null) {
            start = point[0];
        }

        if (point[3] !== 1 && start !== null) {
            zones.push({ from: start, to: point[0] });
            start = null;
        }
    }

    if (start !== null) {
        zones.push({ from: start, to: maxDistance.value });
    }

    return zones.map((zone) => ({
        x: round(x.value(zone.from)),
        width: Math.max(1, round(x.value(zone.to) - x.value(zone.from))),
    }));
};
</script>

<template>
    <div ref="host">
        <svg :viewBox="`0 0 ${box.width} ${box.height}`" class="h-auto w-full" role="img" aria-label="Телеметрия на една обиколка">
            <!-- Скорост -->
            <g>
                <line
                    v-for="tick in speedTicks"
                    :key="`s-${tick}`"
                    :x1="box.left"
                    :x2="box.left + box.innerWidth"
                    :y1="round(ySpeed(tick))"
                    :y2="round(ySpeed(tick))"
                    stroke="#27272a"
                    stroke-width="1"
                    vector-effect="non-scaling-stroke"
                />
                <text
                    v-for="tick in speedTicks"
                    :key="`st-${tick}`"
                    :x="box.left - 8"
                    :y="round(ySpeed(tick)) + baselineShift(fontSize)"
                    text-anchor="end"
                    fill="#83838d"
                    :font-size="fontSize"
                    style="font-variant-numeric: tabular-nums"
                >
                    {{ Math.round(tick) }}
                </text>
                <polyline
                    v-for="serie in series"
                    :key="`sl-${serie.number}`"
                    :points="speedLine(serie)"
                    fill="none"
                    :stroke="serie.colour"
                    stroke-width="2"
                    stroke-linejoin="round"
                    vector-effect="non-scaling-stroke"
                />
                <text :x="box.left" :y="panels.speed.top - 2" fill="#71717a" :font-size="captionSize" letter-spacing="0.08em">
                    СКОРОСТ, КМ/Ч
                </text>
            </g>

            <!-- Газ и спирачка -->
            <g>
                <rect
                    v-for="(zone, i) in brakeZones(series[0])"
                    :key="`b-${i}`"
                    :x="zone.x"
                    :y="panels.throttle.top"
                    :width="zone.width"
                    :height="panels.throttle.height"
                    fill="#e10600"
                    opacity="0.22"
                />
                <polyline
                    v-for="serie in series"
                    :key="`tl-${serie.number}`"
                    :points="throttleLine(serie)"
                    fill="none"
                    :stroke="serie.colour"
                    stroke-width="1.6"
                    stroke-linejoin="round"
                    vector-effect="non-scaling-stroke"
                />
                <text :x="box.left" :y="panels.throttle.top - 2" fill="#71717a" :font-size="captionSize" letter-spacing="0.08em">
                    ГАЗ, % · ЧЕРВЕНОТО Е СПИРАЧКА
                </text>
            </g>

            <!-- Предавка -->
            <g>
                <text
                    v-for="gear in [1, maxGear]"
                    :key="`gl-${gear}`"
                    :x="box.left - 8"
                    :y="round(yGear(gear)) + baselineShift(fontSize)"
                    text-anchor="end"
                    fill="#83838d"
                    :font-size="fontSize"
                    style="font-variant-numeric: tabular-nums"
                >
                    {{ gear }}
                </text>
                <polyline
                    v-for="serie in series"
                    :key="`g-${serie.number}`"
                    :points="gearSteps(serie)"
                    fill="none"
                    :stroke="serie.colour"
                    stroke-width="1.6"
                    stroke-linejoin="miter"
                    vector-effect="non-scaling-stroke"
                />
                <text :x="box.left" :y="panels.gear.top - 2" fill="#71717a" :font-size="captionSize" letter-spacing="0.08em">
                    ПРЕДАВКА
                </text>
            </g>

            <text
                v-for="tick in distanceTicks"
                :key="`d-${tick}`"
                :x="round(x(tick))"
                :y="box.height - 8"
                text-anchor="middle"
                fill="#83838d"
                :font-size="fontSize"
                style="font-variant-numeric: tabular-nums"
            >
                {{ Math.round(tick / 100) / 10 }}
            </text>
        </svg>

        <p class="mt-1 text-center uppercase tracking-wide text-zinc-600" :class="isNarrow ? 'text-xs' : 'text-[11px]'">
            Дистанция, км
        </p>

        <div class="mt-3 flex flex-wrap gap-x-4 gap-y-2 border-t border-zinc-800 pt-3 text-xs">
            <span v-for="serie in series" :key="serie.number" class="inline-flex items-center gap-2 text-zinc-300">
                <i class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ backgroundColor: serie.colour }" aria-hidden="true" />
                {{ serie.name }}
                <span class="font-mono tabular-nums text-zinc-500">{{ serie.time }} · обиколка {{ serie.lap }}</span>
            </span>
            <span class="text-zinc-600">Върхът на скоростта е {{ bg(maxSpeed, 0) }} км/ч</span>
        </div>
    </div>
</template>
