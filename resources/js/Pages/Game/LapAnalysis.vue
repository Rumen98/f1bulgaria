<script setup>
/**
 * Анализ на обиколката: скорост по дистанция (със спирачни зони), делта срещу
 * духа и картата на пистата по сектори. Чист 2D canvas — нула WebGL, едно
 * прерисуване при промяна на данните (< 1 ms за ~500 бина).
 *
 * Данните идват от Game.getLapAnalysis() (виж договора в Index.vue):
 * бинове по 10 m по дължината на обиколката. Всичко освен `speed` е по
 * избор — липсващите графики просто не се рисуват.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    analysis: { type: Object, required: true },
    // Цветовете на секторите (purple/green/yellow/none) — както в HUD-а.
    sectorStates: { type: Array, default: () => ['none', 'none', 'none'] },
    // Очертанието на пистата (нормализирани 0..1 точки, Game.minimap.path).
    outline: { type: Array, default: null },
});

const canvasRef = ref(null);

const WIDTH = 640;
const HEIGHT = 300;
const PAD = { left: 40, right: 12, top: 10, bottom: 18 };
const MAP_SIZE = 96;

const STATE_STROKE = {
    purple: '#e879f9',
    green: '#34d399',
    yellow: '#fbbf24',
    none: 'rgba(255,255,255,0.55)',
};

const STATE_LABEL = {
    purple: 'Рекорд на пистата',
    green: 'Лично подобрение',
    yellow: 'По-бавен сектор',
    none: 'Без сравнение',
};

const finiteValues = (values) => Array.isArray(values)
    ? values.filter((value) => Number.isFinite(value))
    : [];

const average = (values) => values.length > 0
    ? values.reduce((sum, value) => sum + value, 0) / values.length
    : null;

const shareAbove = (values, threshold) => {
    const finite = finiteValues(values);
    return finite.length > 0
        ? Math.round((finite.filter((value) => value > threshold).length / finite.length) * 100)
        : null;
};

const countZones = (values, threshold) => {
    if (!Array.isArray(values)) {
        return null;
    }
    let zones = 0;
    let active = false;
    for (const value of values) {
        const next = Number.isFinite(value) && value > threshold;
        if (next && !active) {
            zones++;
        }
        active = next;
    }
    return zones;
};

const formatKmh = (value) => value === null
    ? '—'
    : `${Math.round(value).toLocaleString('bg-BG')} км/ч`;

const formatSignedSeconds = (value) => {
    if (!Number.isFinite(value)) {
        return '—';
    }
    const sign = value > 0 ? '+' : value < 0 ? '−' : '±';
    return `${sign}${Math.abs(value).toFixed(3)} сек`;
};

const summaryMetrics = computed(() => {
    const speed = finiteValues(props.analysis?.speed);
    const delta = finiteValues(props.analysis?.delta);
    const metrics = [
        { label: 'Макс. скорост', value: formatKmh(speed.length ? Math.max(...speed) : null) },
        { label: 'Средна скорост', value: formatKmh(average(speed)) },
    ];
    const brakeZones = countZones(props.analysis?.brake, 0.5);
    if (brakeZones !== null) {
        metrics.push({ label: 'Спирачни зони', value: String(brakeZones) });
    }
    const fullThrottle = shareAbove(props.analysis?.throttle, 0.9);
    if (fullThrottle !== null) {
        metrics.push({ label: 'Пълна газ', value: `${fullThrottle}% от обиколката` });
    }
    if (delta.length > 0) {
        metrics.push({
            label: 'Финална делта',
            value: formatSignedSeconds(delta[delta.length - 1]),
            tone: delta[delta.length - 1] <= 0 ? 'text-emerald-300' : 'text-red-300',
        });
    }
    return metrics;
});

const sectorSummary = computed(() => props.sectorStates.slice(0, 3).map((state, index) => ({
    sector: `S${index + 1}`,
    state,
    label: STATE_LABEL[state] ?? STATE_LABEL.none,
    color: STATE_STROKE[state] ?? STATE_STROKE.none,
})));

const accessibleSummary = computed(() => [
    ...summaryMetrics.value.map((metric) => `${metric.label}: ${metric.value}`),
    ...sectorSummary.value.map((sector) => `${sector.sector}: ${sector.label}`),
].join('. '));

/**
 * Секторните граници като фракции от дължината — от анализа, иначе трети
 * (както sim.js ги дели по прогрес).
 *
 * @returns {[number, number]}
 */
const sectorBreaks = () => {
    const breaks = props.analysis?.sectors;
    if (Array.isArray(breaks) && breaks.length >= 2) {
        return [breaks[0], breaks[1]];
    }

    return [1 / 3, 2 / 3];
};

const draw = () => {
    const canvas = canvasRef.value;
    const analysis = props.analysis;
    if (!canvas || !analysis?.speed?.length) {
        return;
    }

    const pixelRatio = Math.min(3, Math.max(1, window.devicePixelRatio || 1));
    const physicalWidth = Math.round(WIDTH * pixelRatio);
    const physicalHeight = Math.round(HEIGHT * pixelRatio);
    if (canvas.width !== physicalWidth || canvas.height !== physicalHeight) {
        canvas.width = physicalWidth;
        canvas.height = physicalHeight;
    }

    const ctx = canvas.getContext('2d');
    ctx.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0);
    ctx.clearRect(0, 0, WIDTH, HEIGHT);

    const hasDelta = analysis.delta?.length > 0;
    const hasOutline = Array.isArray(props.outline) && props.outline.length > 1;
    const plotWidth = WIDTH - PAD.left - PAD.right - (hasOutline ? MAP_SIZE + 16 : 0);
    const speedHeight = hasDelta ? 170 : 240;
    const deltaTop = PAD.top + speedHeight + 24;
    const deltaHeight = HEIGHT - deltaTop - PAD.bottom;

    drawSpeed(ctx, analysis, PAD.top, speedHeight, plotWidth);
    if (hasDelta) {
        drawDelta(ctx, analysis, deltaTop, deltaHeight, plotWidth);
    }
    if (hasOutline) {
        drawMap(ctx, WIDTH - PAD.right - MAP_SIZE, PAD.top + 4);
    }
};

/**
 * Скорост по дистанция: спирачните зони са червени ивици отдолу, газта —
 * тънка зелена лента, духът — пунктир.
 */
const drawSpeed = (ctx, analysis, top, height, width) => {
    const bins = analysis.speed.length;
    const xAt = (i) => PAD.left + (i / Math.max(1, bins - 1)) * width;
    let maxSpeed = 100;
    for (let i = 0; i < bins; i++) {
        maxSpeed = Math.max(maxSpeed, analysis.speed[i], analysis.ghostSpeed?.[i] ?? 0);
    }
    maxSpeed = Math.ceil(maxSpeed / 50) * 50;
    const yAt = (v) => top + height - (v / maxSpeed) * height;

    // Решетка на всеки 50 км/ч.
    ctx.strokeStyle = 'rgba(255,255,255,0.08)';
    ctx.fillStyle = 'rgba(255,255,255,0.4)';
    ctx.font = '10px Figtree, system-ui, sans-serif';
    ctx.textAlign = 'right';
    ctx.textBaseline = 'middle';
    ctx.lineWidth = 1;
    for (let v = 0; v <= maxSpeed; v += 50) {
        const y = yAt(v);
        ctx.beginPath();
        ctx.moveTo(PAD.left, y);
        ctx.lineTo(PAD.left + width, y);
        ctx.stroke();
        ctx.fillText(String(v), PAD.left - 6, y);
    }

    // Спирачни зони (спирачка > 0.5) — отдолу нагоре, полупрозрачно.
    if (analysis.brake?.length === bins) {
        ctx.fillStyle = 'rgba(239,68,68,0.18)';
        let start = -1;
        for (let i = 0; i <= bins; i++) {
            const braking = i < bins && analysis.brake[i] > 0.5;
            if (braking && start < 0) {
                start = i;
            } else if (!braking && start >= 0) {
                ctx.fillRect(xAt(start), top, Math.max(1, xAt(i - 1) - xAt(start)), height);
                start = -1;
            }
        }
    }

    // Газ — тънка лента в долния край (плътност = ниво).
    if (analysis.throttle?.length === bins) {
        for (let i = 0; i < bins - 1; i++) {
            const level = analysis.throttle[i];
            if (level <= 0.05) {
                continue;
            }
            ctx.fillStyle = `rgba(52,211,153,${0.15 + level * 0.5})`;
            ctx.fillRect(xAt(i), top + height - 4, xAt(i + 1) - xAt(i) + 0.5, 4);
        }
    }

    // Секторни граници.
    const breaks = sectorBreaks();
    for (let s = 0; s < 2; s++) {
        const x = PAD.left + breaks[s] * width;
        ctx.strokeStyle = STATE_STROKE[props.sectorStates[s + 1]] ?? STATE_STROKE.none;
        ctx.setLineDash([3, 3]);
        ctx.beginPath();
        ctx.moveTo(x, top);
        ctx.lineTo(x, top + height);
        ctx.stroke();
        ctx.setLineDash([]);
    }

    // Духът (пунктир) под играча.
    if (analysis.ghostSpeed?.length === bins) {
        ctx.strokeStyle = 'rgba(159,200,255,0.7)';
        ctx.setLineDash([4, 3]);
        ctx.lineWidth = 1.2;
        ctx.beginPath();
        for (let i = 0; i < bins; i++) {
            const x = xAt(i);
            const y = yAt(analysis.ghostSpeed[i]);
            if (i === 0) {
                ctx.moveTo(x, y);
            } else {
                ctx.lineTo(x, y);
            }
        }
        ctx.stroke();
        ctx.setLineDash([]);
    }

    ctx.strokeStyle = '#ffffff';
    ctx.lineWidth = 1.8;
    ctx.lineJoin = 'round';
    ctx.beginPath();
    for (let i = 0; i < bins; i++) {
        const x = xAt(i);
        const y = yAt(analysis.speed[i]);
        if (i === 0) {
            ctx.moveTo(x, y);
        } else {
            ctx.lineTo(x, y);
        }
    }
    ctx.stroke();

    ctx.fillStyle = 'rgba(255,255,255,0.4)';
    ctx.textAlign = 'left';
    ctx.textBaseline = 'top';
    ctx.fillText('км/ч', PAD.left, top + height + 4);
    ctx.textAlign = 'right';
    ctx.fillText(`${((analysis.length ?? bins * 10) / 1000).toFixed(3)} км`, PAD.left + width, top + height + 4);
};

/** Делта срещу духа: над нулата (изоставаш) червено, под нея зелено. */
const drawDelta = (ctx, analysis, top, height, width) => {
    const bins = analysis.delta.length;
    const xAt = (i) => PAD.left + (i / Math.max(1, bins - 1)) * width;
    let maxAbs = 0.25;
    for (let i = 0; i < bins; i++) {
        maxAbs = Math.max(maxAbs, Math.abs(analysis.delta[i]));
    }
    const mid = top + height / 2;
    const yAt = (d) => mid - (d / maxAbs) * (height / 2);

    ctx.strokeStyle = 'rgba(255,255,255,0.18)';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(PAD.left, mid);
    ctx.lineTo(PAD.left + width, mid);
    ctx.stroke();

    ctx.fillStyle = 'rgba(255,255,255,0.4)';
    ctx.font = '10px Figtree, system-ui, sans-serif';
    ctx.textAlign = 'right';
    ctx.textBaseline = 'middle';
    ctx.fillText(`+${maxAbs.toFixed(2)}`, PAD.left - 6, top + 4);
    ctx.fillText(`−${maxAbs.toFixed(2)}`, PAD.left - 6, top + height - 4);

    // Запълване спрямо нулата: две части, за да е всяка в своя цвят.
    for (const sign of [1, -1]) {
        ctx.fillStyle = sign > 0 ? 'rgba(239,68,68,0.3)' : 'rgba(52,211,153,0.3)';
        ctx.beginPath();
        ctx.moveTo(xAt(0), mid);
        for (let i = 0; i < bins; i++) {
            const d = analysis.delta[i];
            ctx.lineTo(xAt(i), yAt(sign > 0 ? Math.max(0, d) : Math.min(0, d)));
        }
        ctx.lineTo(xAt(bins - 1), mid);
        ctx.closePath();
        ctx.fill();
    }

    ctx.strokeStyle = '#ffffff';
    ctx.lineWidth = 1.4;
    ctx.beginPath();
    for (let i = 0; i < bins; i++) {
        const x = xAt(i);
        const y = yAt(analysis.delta[i]);
        if (i === 0) {
            ctx.moveTo(x, y);
        } else {
            ctx.lineTo(x, y);
        }
    }
    ctx.stroke();

    ctx.fillStyle = 'rgba(255,255,255,0.4)';
    ctx.textAlign = 'left';
    ctx.textBaseline = 'top';
    ctx.fillText('делта срещу духа (s)', PAD.left, top + height + 4);
};

/** Картата: очертанието в три цвята по състоянието на секторите. */
const drawMap = (ctx, left, top) => {
    const outline = props.outline;
    if (!Array.isArray(outline) || outline.length < 2) {
        return;
    }
    const breaks = sectorBreaks();
    const count = outline.length;
    const bounds = [0, Math.floor(breaks[0] * count), Math.floor(breaks[1] * count), count];

    ctx.lineWidth = 3;
    ctx.lineJoin = 'round';
    ctx.lineCap = 'round';
    for (let s = 0; s < 3; s++) {
        ctx.strokeStyle = STATE_STROKE[props.sectorStates[s]] ?? STATE_STROKE.none;
        ctx.beginPath();
        for (let i = bounds[s]; i <= bounds[s + 1]; i++) {
            const [x, y] = outline[i % count];
            const px = left + x * MAP_SIZE;
            const py = top + y * MAP_SIZE;
            if (i === bounds[s]) {
                ctx.moveTo(px, py);
            } else {
                ctx.lineTo(px, py);
            }
        }
        ctx.stroke();
    }
};

let frameId = 0;
const scheduleDraw = () => {
    if (typeof requestAnimationFrame !== 'function') {
        draw();
        return;
    }
    cancelAnimationFrame(frameId);
    frameId = requestAnimationFrame(draw);
};

onMounted(() => {
    scheduleDraw();
    window.addEventListener('resize', scheduleDraw, { passive: true });
});
watch(() => [props.analysis, props.sectorStates, props.outline], scheduleDraw);
onBeforeUnmount(() => {
    window.removeEventListener('resize', scheduleDraw);
    if (typeof cancelAnimationFrame === 'function') {
        cancelAnimationFrame(frameId);
    }
});
</script>

<template>
    <section class="rounded-xl border border-white/5 bg-black/35 p-2.5 sm:p-3" aria-labelledby="lap-analysis-title">
        <h3 id="lap-analysis-title" class="sr-only">Анализ на обиколката</h3>

        <dl class="mb-3 grid grid-cols-2 gap-1.5 sm:grid-cols-3">
            <div
                v-for="metric in summaryMetrics"
                :key="metric.label"
                class="rounded-lg border border-white/5 bg-white/[0.035] px-2.5 py-2"
            >
                <dt class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500">{{ metric.label }}</dt>
                <dd class="mt-0.5 font-display text-sm font-bold tabular-nums text-zinc-100" :class="metric.tone">
                    {{ metric.value }}
                </dd>
            </div>
        </dl>

        <div class="overflow-hidden rounded-lg bg-zinc-950/65">
            <canvas
                ref="canvasRef"
                :width="WIDTH"
                :height="HEIGHT"
                class="block aspect-[32/15] h-auto w-full"
                role="img"
                aria-label="Графика на скоростта по дистанция, спирачните зони и делтата срещу духа"
                aria-describedby="lap-analysis-summary lap-analysis-legend"
            >
                {{ accessibleSummary }}
            </canvas>
        </div>

        <div id="lap-analysis-legend" class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[11px] text-zinc-400 sm:grid-cols-4">
            <span class="flex items-center gap-1.5">
                <span class="inline-block h-0.5 w-4 bg-white" aria-hidden="true"></span>
                Бяла линия: твоята скорост
            </span>
            <span v-if="analysis.ghostSpeed?.length" class="flex items-center gap-1.5">
                <span class="inline-block w-4 border-t-2 border-dashed border-sky-300" aria-hidden="true"></span>
                Син пунктир: духът
            </span>
            <span class="flex items-center gap-1.5">
                <span class="inline-block h-2.5 w-4 bg-[repeating-linear-gradient(135deg,rgba(239,68,68,.55)_0_2px,transparent_2px_4px)]" aria-hidden="true"></span>
                Червени полета: спиране
            </span>
            <span class="flex items-center gap-1.5">
                <span class="inline-block h-1 w-4 bg-emerald-400/70" aria-hidden="true"></span>
                Зелена лента: газ
            </span>
        </div>

        <ul class="mt-2 flex flex-wrap gap-x-3 gap-y-1 border-t border-white/5 pt-2 text-[11px] text-zinc-400" aria-label="Състояние на секторите">
            <li v-for="sector in sectorSummary" :key="sector.sector" class="flex items-center gap-1.5">
                <span
                    class="grid h-4 min-w-4 place-items-center rounded-sm border text-[8px] font-black text-zinc-950"
                    :style="{ borderColor: sector.color, backgroundColor: sector.color }"
                    aria-hidden="true"
                >{{ sector.sector.slice(1) }}</span>
                <strong class="text-zinc-300">{{ sector.sector }}</strong>
                <span>{{ sector.label }}</span>
            </li>
        </ul>

        <p id="lap-analysis-summary" class="sr-only">
            {{ accessibleSummary }}
        </p>
    </section>
</template>
