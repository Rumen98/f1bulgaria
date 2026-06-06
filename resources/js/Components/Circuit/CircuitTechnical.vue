<script setup>
import { computed } from 'vue';

const props = defineProps({
    tech: { type: Object, default: null },
});

const typeLabel = computed(
    () => ({ street: 'Уличен', permanent: 'Постоянен', hybrid: 'Хибриден' })[props.tech?.type] ?? null,
);
const gripLabel = (v) => ({ low: 'Ниско', medium: 'Средно', high: 'Високо' })[v] ?? '—';

// Има ли изобщо смислени данни (поне дължина или брой завои)?
const hasData = computed(() => props.tech && (props.tech.length_km || props.tech.turns_count));

const cards = computed(() => {
    if (!props.tech) {
        return [];
    }
    const t = props.tech;
    return [
        t.length_km ? { label: 'Дължина', value: `${t.length_km} км` } : null,
        t.laps ? { label: 'Обиколки', value: t.laps } : null,
        t.turns_count ? { label: 'Завои', value: t.turns_count } : null,
        t.longest_straight_m ? { label: 'Най-дълга права', value: `${t.longest_straight_m} м` } : null,
        t.elevation_change_m ? { label: 'Денивелация', value: `${t.elevation_change_m} м` } : null,
        typeLabel.value ? { label: 'Тип', value: typeLabel.value } : null,
    ].filter(Boolean);
});

const cornerCards = computed(() => {
    const t = props.tech;
    if (!t || (!t.fast_corners && !t.medium_corners && !t.slow_corners)) {
        return [];
    }
    return [
        { label: 'Бързи завои', value: t.fast_corners ?? 0, color: 'text-emerald-400' },
        { label: 'Средни завои', value: t.medium_corners ?? 0, color: 'text-amber-400' },
        { label: 'Бавни завои', value: t.slow_corners ?? 0, color: 'text-sky-400' },
    ];
});
</script>

<template>
    <section v-if="hasData">
        <h2 class="mb-3 text-lg font-bold text-white">Технически данни</h2>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <div v-for="c in cards" :key="c.label" class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 text-center">
                <div class="text-xl font-black tabular-nums text-white">{{ c.value }}</div>
                <div class="mt-1 text-xs uppercase tracking-wide text-zinc-500">{{ c.label }}</div>
            </div>
        </div>

        <div v-if="cornerCards.length" class="mt-3 grid grid-cols-3 gap-3">
            <div v-for="c in cornerCards" :key="c.label" class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 text-center">
                <div class="text-2xl font-black tabular-nums" :class="c.color">{{ c.value }}</div>
                <div class="mt-1 text-xs uppercase tracking-wide text-zinc-500">{{ c.label }}</div>
            </div>
        </div>

        <div class="mt-3 flex flex-wrap gap-3">
            <div v-if="tech.lap_record_time" class="flex-1 rounded-xl border border-zinc-800 bg-zinc-900/60 p-4">
                <div class="text-xs uppercase tracking-wide text-zinc-500">⏱️ Рекорд на пистата</div>
                <div class="mt-1 text-lg font-black tabular-nums text-white">{{ tech.lap_record_time }}</div>
                <div class="text-sm text-zinc-400">{{ tech.lap_record_driver }} · {{ tech.lap_record_year }}</div>
            </div>
            <div v-if="tech.surface_grip" class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-4">
                <div class="text-xs uppercase tracking-wide text-zinc-500">Сцепление</div>
                <div class="mt-1 font-semibold text-zinc-200">{{ gripLabel(tech.surface_grip) }}</div>
            </div>
            <div v-if="tech.overtaking_difficulty" class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-4">
                <div class="text-xs uppercase tracking-wide text-zinc-500">Изпреварване</div>
                <div class="mt-1 font-semibold text-zinc-200">{{ gripLabel(tech.overtaking_difficulty) }}</div>
            </div>
        </div>
    </section>
</template>
