<script setup>
import { round } from '@/utils/chart';
import { computed } from 'vue';

/**
 * Пистата, изрисувана от реалната линия на болида, оцветена по скорост.
 *
 * Координатите идват от `/v1/location`, а очертанието — от MultiViewer, в
 * СЪЩАТА координатна система, така че двете лягат едно върху друго без
 * преобразуване. Ако очертанието липсва (Cloudflare, стар сезон), картата пак
 * се получава: линията на болида сама описва пистата.
 *
 * Цветовата скала е последователна (тъмно лилаво → жълто), а не дъга. Дъгата
 * изглежда ефектно, но окото не подрежда цветовете ѝ по големина — а тук целият
 * смисъл е да се вижда КЪДЕ е бавно и къде бързо.
 */
const props = defineProps({
    points: { type: Array, required: true },
    outline: { type: Array, default: null },
    corners: { type: Array, default: () => [] },
    rotation: { type: Number, default: 0 },
    driver: { type: String, default: null },
    /**
     * Номерът на обиколката. Не е винаги най-бързата: позиционният феед къса и
     * тогава картата се рисува от най-бързата обиколка, за която ИМА данни.
     * Затова номерът се показва — иначе картата би твърдяла нещо невярно.
     */
    lap: { type: Number, default: null },
});

const RAMP = ['#5b21b6', '#a21caf', '#e02424', '#f97316', '#fbbf24', '#fde68a'];

const box = { width: 800, height: 460, pad: 28 };

const radians = computed(() => (props.rotation * Math.PI) / 180);

/** Завъртане около нулата — така картата стои както по телевизията. */
const spin = ([x, y]) => {
    const cos = Math.cos(radians.value);
    const sin = Math.sin(radians.value);

    return [x * cos - y * sin, x * sin + y * cos];
};

const path = computed(() => props.points.map((p) => [...spin([p[0], p[1]]), p[2]]));
const track = computed(() => (props.outline ?? []).map((p) => spin(p)));
const turns = computed(() => props.corners.map((c) => ({ number: c.number, at: spin([c.x, c.y]) })));

const bounds = computed(() => {
    const xs = [...path.value.map((p) => p[0]), ...track.value.map((p) => p[0])];
    const ys = [...path.value.map((p) => p[1]), ...track.value.map((p) => p[1])];

    return {
        minX: Math.min(...xs),
        maxX: Math.max(...xs),
        minY: Math.min(...ys),
        maxY: Math.max(...ys),
    };
});

/** Еднакъв мащаб по двете оси — иначе пистата излиза сплескана. */
const project = computed(() => {
    const { minX, maxX, minY, maxY } = bounds.value;
    const spanX = Math.max(1, maxX - minX);
    const spanY = Math.max(1, maxY - minY);
    const scale = Math.min(
        (box.width - box.pad * 2) / spanX,
        (box.height - box.pad * 2) / spanY,
    );

    const offsetX = (box.width - spanX * scale) / 2;
    const offsetY = (box.height - spanY * scale) / 2;

    return ([x, y]) => [
        round(offsetX + (x - minX) * scale),
        // y се обръща: в SVG расте надолу.
        round(offsetY + (maxY - y) * scale),
    ];
});

const speeds = computed(() => path.value.map((p) => p[2]));
const minSpeed = computed(() => Math.min(...speeds.value));
const maxSpeed = computed(() => Math.max(...speeds.value));

const colour = (speed) => {
    const span = Math.max(1, maxSpeed.value - minSpeed.value);
    const t = Math.min(1, Math.max(0, (speed - minSpeed.value) / span));
    const scaled = t * (RAMP.length - 1);
    const i = Math.min(RAMP.length - 2, Math.floor(scaled));

    return mix(RAMP[i], RAMP[i + 1], scaled - i);
};

const mix = (from, to, t) => {
    const parse = (hex) => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
    const [r1, g1, b1] = parse(from);
    const [r2, g2, b2] = parse(to);
    const channel = (a, b) => Math.round(a + (b - a) * t);

    return `rgb(${channel(r1, r2)},${channel(g1, g2)},${channel(b1, b2)})`;
};

/** Отсечките носят цвета — една полилиния може да е само в един цвят. */
const segments = computed(() => {
    const out = [];

    for (let i = 1; i < path.value.length; i++) {
        const [x1, y1] = project.value(path.value[i - 1]);
        const [x2, y2] = project.value(path.value[i]);

        out.push({ x1, y1, x2, y2, stroke: colour(path.value[i][2]) });
    }

    return out;
});

const outlinePath = computed(() =>
    track.value.length === 0
        ? null
        : track.value.map((p) => project.value(p).join(',')).join(' '),
);

const legend = computed(() => {
    const span = maxSpeed.value - minSpeed.value;

    return RAMP.map((hex, i) => ({
        hex,
        label: i === 0 ? `${minSpeed.value}` : i === RAMP.length - 1 ? `${maxSpeed.value} км/ч` : null,
        // Средните стъпала носят само цвят — числата им биха се слепили.
        key: `${hex}-${i}`,
        value: Math.round(minSpeed.value + (span * i) / (RAMP.length - 1)),
    }));
});
</script>

<template>
    <div>
        <svg
            :viewBox="`0 0 ${box.width} ${box.height}`"
            class="h-auto w-full"
            role="img"
            :aria-label="`Карта на пистата, оцветена по скорост${driver ? ` — обиколка на ${driver}` : ''}`"
        >
            <!-- Очертанието стои под линията като сива основа: показва цялата
                 писта дори там, където записът има дупка. -->
            <polyline
                v-if="outlinePath"
                :points="outlinePath"
                fill="none"
                stroke="#27272a"
                stroke-width="14"
                stroke-linejoin="round"
                stroke-linecap="round"
            />

            <line
                v-for="(s, i) in segments"
                :key="i"
                :x1="s.x1"
                :y1="s.y1"
                :x2="s.x2"
                :y2="s.y2"
                :stroke="s.stroke"
                stroke-width="5"
                stroke-linecap="round"
            />

            <g v-for="turn in turns" :key="turn.number">
                <circle :cx="project(turn.at)[0]" :cy="project(turn.at)[1]" r="9" fill="#0a0a0a" opacity="0.75" />
                <text
                    :x="project(turn.at)[0]"
                    :y="project(turn.at)[1] + 3.5"
                    text-anchor="middle"
                    fill="#a1a1aa"
                    font-size="10"
                    style="font-variant-numeric: tabular-nums"
                >
                    {{ turn.number }}
                </text>
            </g>
        </svg>

        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-zinc-800 pt-3 text-xs text-zinc-400">
            <span class="inline-flex items-center gap-2">
                <span class="font-mono tabular-nums">{{ minSpeed }}</span>
                <span class="flex h-2.5 w-28 overflow-hidden rounded-full">
                    <i v-for="stop in legend" :key="stop.key" class="h-full flex-1" :style="{ backgroundColor: stop.hex }" />
                </span>
                <span class="font-mono tabular-nums">{{ maxSpeed }} км/ч</span>
            </span>
            <span v-if="driver">Обиколката на {{ driver }}<template v-if="lap"> — номер {{ lap }}</template></span>
            <span v-if="!outline" class="text-zinc-600">Очертанието на пистата е недостъпно — показана е само линията на болида.</span>
        </div>
    </div>
</template>
