<script setup>
import { computed } from 'vue';

/**
 * Отрязъците, в които разликата между първите двама се е менила най-много.
 *
 * Текстът идва готов от RaceMomentsBuilder и тук не се дописва: в данните няма
 * контрафактуал, с който да се провери твърдение за причина, затова картите
 * казват само какво се е случило и с колко. Оттам е и името на секцията —
 * „Къде се решаваше“ описва мястото, не изхода.
 *
 * Клик по карта праща обиколката нагоре: така лентата и трите графики по
 * обиколка застават на същото място, без човек да търси числото с плъзгача.
 */
const props = defineProps({
    moments: { type: Array, required: true },
    totalLaps: { type: Number, required: true },
});

const emit = defineEmits(['select']);

/** Цветовете са същите като маркерите на времевата лента. */
const COLOURS = {
    start: '#a1a1aa',
    pit: '#38bdf8',
    neutralisation: '#fbbf24',
    pace: '#34d399',
    finish: '#fafafa',
};

const laps = computed(() => Math.max(1, props.totalLaps));

const cards = computed(() =>
    props.moments.map((moment, i) => ({
        ...moment,
        // Индексът влиза в ключа, защото два момента може да делят обиколка —
        // старт и първи отрязък почват на една и съща.
        key: `${i}-${moment.from}-${moment.to}`,
        colour: COLOURS[moment.type] ?? '#83838d',
        strip: {
            left: `${((moment.from - 1) / laps.value) * 100}%`,
            width: `${Math.max(1.5, ((moment.to - moment.from + 1) / laps.value) * 100)}%`,
        },
        range:
            moment.from === moment.to
                ? `обиколка ${moment.from} от ${laps.value}`
                : `обиколки ${moment.from}–${moment.to} от ${laps.value}`,
    })),
);
</script>

<template>
    <!-- Tailwind маха точките на списъка, а с тях Safari маха и ролята му —
         затова role и достъпното име стоят изрично. -->
    <ul role="list" aria-label="Къде се решаваше" class="grid gap-3 sm:grid-cols-2">
        <li v-for="card in cards" :key="card.key">
            <button
                type="button"
                class="flex h-full w-full flex-col gap-1 rounded-xl border border-zinc-800 bg-zinc-900/60 p-3 text-left transition duration-200 hover:border-zinc-700 hover:bg-zinc-900"
                @click="emit('select', card.from)"
            >
                <span class="flex items-center gap-2">
                    <i class="h-2.5 w-2.5 shrink-0 rounded-sm" :style="{ backgroundColor: card.colour }" aria-hidden="true" />
                    <span class="font-display text-sm font-bold text-white">{{ card.label }}</span>
                </span>

                <span class="text-sm leading-relaxed text-zinc-300">{{ card.detail }}</span>

                <!-- Лентичката показва къде в състезанието попада моментът:
                     „обиколки 31–38“ значи различно нещо в кръг от 44 и в кръг
                     от 78 обиколки. -->
                <span class="relative mt-auto block h-1 w-full overflow-hidden rounded bg-zinc-800" aria-hidden="true">
                    <span class="absolute inset-y-0 rounded" :style="{ ...card.strip, backgroundColor: card.colour }" />
                </span>

                <span class="font-mono text-[11px] tabular-nums text-zinc-500">{{ card.range }}</span>
            </button>
        </li>
    </ul>
</template>
