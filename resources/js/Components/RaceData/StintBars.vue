<script setup>
import { tyre, tyreHex, tyreLegend } from '@/utils/tyres';
import { computed } from 'vue';

/**
 * Стратегията по гуми: една лента на пилот, разделена на стинтове.
 *
 * Лента, а не линия, защото това е графиката, която работи и на телефон —
 * ширината носи обиколките, а цветът носи състава, и двете се четат с един
 * поглед. Питстоповете са тънки резки върху лентата, не отделни точки.
 */
const props = defineProps({
    stints: { type: Array, required: true },
    totalLaps: { type: Number, required: true },
    neutralisations: { type: Array, default: () => [] },
    /** Обиколката под курсора на времевата лента; null = не се рисува нищо. */
    cursor: { type: Number, default: null },
});

const laps = computed(() => Math.max(1, props.totalLaps));

const percent = (from, to) => {
    // Обиколките са включващи: стинт от 1 до 13 е тринадесет обиколки.
    const width = ((to - from + 1) / laps.value) * 100;

    return {
        left: `${((from - 1) / laps.value) * 100}%`,
        width: `${Math.max(0.6, width)}%`,
    };
};

const legend = computed(() =>
    tyreLegend(props.stints.flatMap((row) => row.segments.map((s) => s.compound))),
);

const bands = computed(() =>
    props.neutralisations.map((window) => percent(window.from, window.to)),
);

/**
 * Курсорът минава през същата скала като сегментите — стинт от една обиколка.
 * Втора сметка тук би дала втори отговор на въпроса къде стои обиколка 20.
 */
const cursorLeft = computed(() => {
    if (!Number.isFinite(props.cursor) || props.cursor < 1 || props.cursor > laps.value) {
        return null;
    }

    return percent(props.cursor, props.cursor).left;
});

const title = (row, segment) => {
    const name = tyre(segment.compound)?.label ?? segment.compound;

    return `${row.name} · ${name}, обиколки ${segment.from}–${segment.to}`;
};
</script>

<template>
    <div>
        <!-- Мащабът е общ за всички ленти, затова номерата на обиколките стоят
             веднъж отгоре, а не под всеки ред. -->
        <div class="mb-2 flex justify-between pl-14 font-mono text-[10px] tabular-nums text-zinc-600">
            <span>1</span>
            <span>{{ Math.round(laps / 2) }}</span>
            <span>{{ laps }}</span>
        </div>

        <div class="relative">
            <ul class="space-y-1.5">
                <li v-for="row in stints" :key="row.number" class="flex items-center gap-2">
                    <span
                        class="w-12 shrink-0 truncate font-mono text-[11px] font-semibold"
                        :style="{ color: row.colour }"
                        :title="row.name"
                    >
                        {{ row.short }}
                    </span>

                    <div class="relative h-5 grow overflow-hidden rounded bg-zinc-800/60">
                        <span
                            v-for="(segment, i) in row.segments"
                            :key="i"
                            class="absolute inset-y-0 opacity-90"
                            :style="{ ...percent(segment.from, segment.to), backgroundColor: tyreHex(segment.compound) }"
                            :title="title(row, segment)"
                        />

                        <span
                            v-for="lap in row.pits"
                            :key="`p-${lap}`"
                            class="absolute inset-y-0 w-px bg-[#0a0a0a]"
                            :style="{ left: `${((lap - 1) / laps) * 100}%` }"
                            :title="`Питстоп, обиколка ${lap}`"
                        />

                        <!-- Неутрализацията се рисува НАД гумите. Под тях е
                             невидима: цветните сегменти покриват целия ред. -->
                        <span
                            v-for="(band, i) in bands"
                            :key="`n-${i}`"
                            class="pointer-events-none absolute inset-y-0 bg-zinc-950/55"
                            :style="band"
                            aria-hidden="true"
                        />
                    </div>
                </li>
            </ul>

            <!-- Курсорът е ЕДНА линия през всички ленти, а не по една на ред:
                 моментът е общ за цялото поле. Стои след списъка, защото
                 лентите са позиционирани — отпред би го покрил ред по ред.
                 Отместването отляво повтаря колоната с имената (w-12 + gap-2). -->
            <span
                v-if="cursorLeft !== null"
                class="pointer-events-none absolute inset-y-0 left-14 right-0"
                aria-hidden="true"
            >
                <span class="absolute inset-y-0 w-px bg-zinc-100/70" :style="{ left: cursorLeft }" />
            </span>
        </div>

        <div class="mt-4 flex flex-wrap gap-x-4 gap-y-2 border-t border-zinc-800 pt-3 text-xs text-zinc-400">
            <span v-for="item in legend" :key="item.key" class="inline-flex items-center gap-1.5">
                <i class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ backgroundColor: item.hex }" aria-hidden="true" />
                {{ item.label }}
            </span>
            <span v-if="bands.length" class="inline-flex items-center gap-1.5">
                <i class="inline-block h-2.5 w-2.5 rounded-sm border border-zinc-700 bg-zinc-950/55" aria-hidden="true" />
                Неутрализация
            </span>
            <span class="inline-flex items-center gap-1.5">
                <i class="inline-block h-2.5 w-px bg-zinc-400" aria-hidden="true" />
                Питстоп
            </span>
        </div>
    </div>
</template>
