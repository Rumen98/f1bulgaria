<script setup>
import { computed } from 'vue';

/**
 * Времевата лента на състезанието: къде в обиколките са питстоповете,
 * смените на позиции и неутрализациите, и коя обиколка е под курсора.
 *
 * ЧЕСТЕН ОБХВАТ: курсорът движи само графиките, чиято ос са обиколките.
 * Телеметрията и картата на пистата носят данни за ЕДНА обиколка — базата
 * съзнателно не пази сурова телеметрия — затова надписът долу изброява
 * поименно какво следва курсора, вместо да обещава цяла подвижна страница.
 *
 * Лентата е на CSS проценти, не SVG: същата скала като StintBars, а текстът
 * остава четим на телефон, без viewBox да го смалява.
 *
 * Наборът от OpenF1 включва и размени от питстопове, и наказания след финала,
 * затова навсякъде тук се казва „смени на позиции“, а не „изпреварвания“.
 */
const props = defineProps({
    totalLaps: { type: Number, required: true },
    /** Обиколката под курсора; липсваща стойност значи началото. */
    lap: { type: Number, default: null },
    /** Редовете от `charts.stints` — оттам идват питстоповете. */
    stints: { type: Array, default: () => [] },
    /** Прозорците от `charts.neutralisations` като [{from, to}]. */
    neutralisations: { type: Array, default: () => [] },
    /** `charts.overtakes` като [[обиколка, брой]]. */
    overtakes: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:lap']);

const laps = computed(() => Math.max(1, props.totalLaps));

/** Курсорът се държи в обхвата — родителят може да подаде и обиколка 0. */
const current = computed(() => {
    if (!Number.isFinite(props.lap)) {
        return 1;
    }

    return Math.min(laps.value, Math.max(1, Math.round(props.lap)));
});

/** Скалата е същата като в StintBars: обиколка 1 започва в нулата. */
const left = (lap) => `${((lap - 1) / laps.value) * 100}%`;

const band = (window) => ({
    left: left(window.from),
    width: `${Math.max(0.6, ((window.to - window.from + 1) / laps.value) * 100)}%`,
});

const bands = computed(() => props.neutralisations.map(band));

/** Спиранията на всички пилоти, събрани по обиколка. */
const pitsByLap = computed(() => {
    const out = new Map();

    for (const row of props.stints) {
        for (const lap of row.pits ?? []) {
            out.set(lap, (out.get(lap) ?? 0) + 1);
        }
    }

    return out;
});

const changesByLap = computed(() => {
    const out = new Map();

    for (const [lap, count] of props.overtakes) {
        out.set(lap, (out.get(lap) ?? 0) + count);
    }

    return out;
});

// Височината на резката носи броя: обиколка с осем спирания и обиколка с едно
// не бива да изглеждат еднакво. Максимумът е дневен, не абсолютен — сравняват
// се обиколки от едно състезание.
const maxPits = computed(() => Math.max(1, ...pitsByLap.value.values()));
const maxChanges = computed(() => Math.max(1, ...changesByLap.value.values()));

const height = (count, max) => `${18 + (count / max) * 27}%`;

/** Само обиколките, на които изобщо се е случило нещо, стават маркери. */
const markers = computed(() =>
    [...new Set([...pitsByLap.value.keys(), ...changesByLap.value.keys()])]
        .filter((lap) => Number.isFinite(lap) && lap >= 1 && lap <= laps.value)
        .sort((a, b) => a - b)
        .map((lap) => ({
            lap,
            pits: pitsByLap.value.get(lap) ?? 0,
            changes: changesByLap.value.get(lap) ?? 0,
        })),
);

// Разделени, защото v-if и v-for не се слагат на един елемент: филтърът тук е
// по-евтин от скрит елемент за всяка обиколка без събитие от този вид.
const pitMarkers = computed(() => markers.value.filter((marker) => marker.pits > 0));
const changeMarkers = computed(() => markers.value.filter((marker) => marker.changes > 0));

const neutralised = (lap) => props.neutralisations.some((window) => lap >= window.from && lap <= window.to);

const eventsAt = (lap) => {
    const parts = [];
    const pits = pitsByLap.value.get(lap) ?? 0;
    const changes = changesByLap.value.get(lap) ?? 0;

    if (pits > 0) {
        parts.push(`${pits} ${pits === 1 ? 'питстоп' : 'питстопа'}`);
    }

    if (changes > 0) {
        parts.push(`${changes} ${changes === 1 ? 'смяна на позиция' : 'смени на позиции'}`);
    }

    return parts;
};

const markerLabel = (marker) => `Обиколка ${marker.lap}: ${eventsAt(marker.lap).join(', ')}`;

/** Без този ред местенето на плъзгача е само число, което се сменя. */
const summary = computed(() => {
    const parts = eventsAt(current.value);

    if (neutralised(current.value)) {
        parts.push('неутрализация');
    }

    return parts.length > 0 ? parts.join(' · ') : 'Без отбелязано събитие';
});
</script>

<template>
    <div>
        <div class="mb-2 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
            <p class="font-display text-lg font-black text-white">
                Обиколка <span class="tabular-nums">{{ current }}</span>
                <span class="font-sans text-sm font-normal text-zinc-500"> от {{ laps }}</span>
            </p>
            <p class="text-sm text-zinc-400">{{ summary }}</p>
        </div>

        <div class="relative h-11 overflow-hidden rounded bg-zinc-800/60">
            <span
                v-for="(window, i) in bands"
                :key="`n-${i}`"
                class="pointer-events-none absolute inset-y-0 bg-amber-400/20"
                :style="window"
                aria-hidden="true"
            />

            <!-- Смените на позиции растат отгоре, питстоповете — отдолу. Двата
                 вида се случват на едни и същи обиколки и наслагани един върху
                 друг биха се четели като един по-висок стълб. -->
            <span
                v-for="marker in changeMarkers"
                :key="`c-${marker.lap}`"
                class="pointer-events-none absolute top-0 w-0.5 -translate-x-px rounded-b bg-violet-400"
                :style="{ left: left(marker.lap), height: height(marker.changes, maxChanges) }"
                aria-hidden="true"
            />

            <span
                v-for="marker in pitMarkers"
                :key="`p-${marker.lap}`"
                class="pointer-events-none absolute bottom-0 w-0.5 -translate-x-px rounded-t bg-sky-400"
                :style="{ left: left(marker.lap), height: height(marker.pits, maxPits) }"
                aria-hidden="true"
            />

            <span
                class="pointer-events-none absolute inset-y-0 w-0.5 -translate-x-px bg-zinc-100"
                :style="{ left: left(current) }"
                aria-hidden="true"
            />

            <!-- Полето за пръст е цялата височина на лентата, а самата резка е
                 два пиксела: с палец се цели маркерът, не линията. Извън реда
                 на табулацията са нарочно — плъзгачът отдолу стига до всяка
                 обиколка с клавиш, а трийсет спирки за трийсет обиколки биха
                 били по-лоши за клавиатурата, не по-добри. -->
            <button
                v-for="marker in markers"
                :key="`m-${marker.lap}`"
                type="button"
                tabindex="-1"
                class="absolute inset-y-0 w-5 -translate-x-1/2"
                :style="{ left: left(marker.lap) }"
                :aria-label="markerLabel(marker)"
                :title="markerLabel(marker)"
                @click="emit('update:lap', marker.lap)"
            />
        </div>

        <div class="mt-1 flex justify-between font-mono text-[10px] uppercase tracking-wide text-zinc-600">
            <span>Старт</span>
            <span>Финал</span>
        </div>

        <input
            type="range"
            min="1"
            :max="laps"
            step="1"
            :value="current"
            class="mt-1 h-11 w-full cursor-pointer accent-red-600"
            aria-label="Обиколка"
            :aria-valuetext="`Обиколка ${current} от ${laps}`"
            @input="emit('update:lap', Number($event.target.value))"
        />

        <div class="flex flex-wrap gap-x-4 gap-y-2 border-t border-zinc-800 pt-3 text-xs text-zinc-400">
            <span class="inline-flex items-center gap-1.5">
                <i class="inline-block h-2.5 w-2.5 rounded-sm bg-sky-400" aria-hidden="true" />
                Питстоп
            </span>
            <span class="inline-flex items-center gap-1.5">
                <i class="inline-block h-2.5 w-2.5 rounded-sm bg-violet-400" aria-hidden="true" />
                Смени на позиции
            </span>
            <span v-if="bands.length" class="inline-flex items-center gap-1.5">
                <i class="inline-block h-2.5 w-2.5 rounded-sm border border-zinc-700 bg-amber-400/20" aria-hidden="true" />
                Неутрализация
            </span>
        </div>

        <p class="mt-2 text-xs leading-relaxed text-zinc-500">
            Курсорът се движи в трите графики по обиколка: стратегията по гуми, позициите и изоставането от лидера.
            Телеметрията и картата на пистата показват една-единствена обиколка и остават на място.
        </p>
    </div>
</template>
