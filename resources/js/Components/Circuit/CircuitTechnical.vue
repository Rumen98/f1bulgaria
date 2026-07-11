<script setup>
import Card from '@/Components/UI/Card.vue';
import StatTile from '@/Components/UI/StatTile.vue';
import { computed } from 'vue';

const props = defineProps({
    tech: { type: Object, default: null },
});

const typeLabel = computed(
    () => ({ street: 'Уличен', permanent: 'Постоянен', hybrid: 'Хибриден' })[props.tech?.type] ?? null,
);
const gripLabel = (v) => ({ low: 'Ниско', medium: 'Средно', high: 'Високо' })[v] ?? '—';
// Отделна карта за трудност — женски род („Ниска трудност"), не съвпада със сцеплението.
const difficultyLabel = (v) => ({ low: 'Ниска', medium: 'Средна', high: 'Висока' })[v] ?? '—';

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
        <h2 class="mb-3 font-display text-lg font-bold text-white">Технически данни</h2>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <StatTile v-for="c in cards" :key="c.label" :label="c.label">{{ c.value }}</StatTile>
        </div>

        <div v-if="cornerCards.length" class="mt-3 grid grid-cols-3 gap-3">
            <div v-for="c in cornerCards" :key="c.label" class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 text-center">
                <div class="font-display text-2xl font-black tabular-nums" :class="c.color">{{ c.value }}</div>
                <div class="mt-1 text-xs uppercase tracking-wide text-zinc-500">{{ c.label }}</div>
            </div>
        </div>

        <div class="mt-3 flex flex-wrap gap-3">
            <Card v-if="tech.lap_record_time" class="flex-1">
                <div class="text-xs uppercase tracking-wide text-zinc-500">⏱️ Рекорд на пистата</div>
                <div class="mt-1 font-display text-lg font-black tabular-nums text-white">{{ tech.lap_record_time }}</div>
                <div class="text-sm text-zinc-400">{{ tech.lap_record_driver }} · {{ tech.lap_record_year }}</div>
            </Card>
            <Card v-if="tech.surface_grip">
                <div class="text-xs uppercase tracking-wide text-zinc-500">Сцепление</div>
                <div class="mt-1 font-semibold text-zinc-200">{{ gripLabel(tech.surface_grip) }}</div>
            </Card>
            <Card v-if="tech.overtaking_difficulty">
                <div class="text-xs uppercase tracking-wide text-zinc-500">Изпреварване</div>
                <div class="mt-1 font-semibold text-zinc-200">{{ difficultyLabel(tech.overtaking_difficulty) }}</div>
            </Card>
        </div>
    </section>
</template>
