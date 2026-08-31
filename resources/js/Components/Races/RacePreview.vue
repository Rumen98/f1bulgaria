<script setup>
import { NEUTRAL_DOT_COLOR } from '@/utils/racing';
import { hasRoute } from '@/utils/routes';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    preview: { type: Object, required: true },
    circuit: { type: String, default: null },
});

const canOpenDriver = computed(() => hasRoute('drivers.show'));
const canOpenCircuit = computed(() => hasRoute('circuits.show'));

// Показваме реда само ако наистина има какво да каже.
const facts = computed(() => {
    const rows = [];

    if (props.preview.most_wins) {
        rows.push({
            label: 'Най-много победи тук',
            value: `${props.preview.most_wins.name} · ${props.preview.most_wins.count}×`,
        });
    }

    if (props.preview.avg_winner_grid !== null && props.preview.avg_winner_grid !== undefined) {
        rows.push({
            label: 'Средна стартова позиция на победителя',
            value: `P${Number(props.preview.avg_winner_grid).toFixed(1)}`,
        });
    }

    if (props.preview.pole_to_win !== null && props.preview.pole_to_win !== undefined) {
        rows.push({
            label: 'Pole → победа',
            value: `${Math.round(Number(props.preview.pole_to_win))}%`,
        });
    }

    return rows;
});

const hasAnything = computed(
    () => facts.value.length > 0 || props.preview.last_winners?.length > 0 || props.preview.all_time?.length > 0,
);
</script>

<template>
    <!-- Между два кръга страницата беше заглавие плюс разписание. Всичко тук е
         вече кеширано в CircuitStatsService — нов източник на данни няма. -->
    <section v-if="hasAnything" class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-5">
        <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-display text-lg font-bold text-white">Историята на пистата</h2>
            <Link
                v-if="canOpenCircuit && preview.circuit_slug"
                :href="route('circuits.show', preview.circuit_slug)"
                class="text-sm font-medium text-red-500 transition hover:text-red-400"
            >
                Цялата писта →
            </Link>
        </div>

        <dl v-if="facts.length" class="mb-5 grid gap-3 sm:grid-cols-3">
            <div v-for="fact in facts" :key="fact.label" class="rounded-lg border border-zinc-800 bg-black/30 p-3">
                <dt class="text-[11px] uppercase tracking-wide text-zinc-500">{{ fact.label }}</dt>
                <dd class="mt-1 font-semibold text-white">{{ fact.value }}</dd>
            </div>
        </dl>

        <div class="grid gap-6 sm:grid-cols-2">
            <div v-if="preview.last_winners?.length" class="min-w-0">
                <h3 class="mb-2 text-xs font-black uppercase tracking-wide text-zinc-500">Последни победители</h3>
                <ul class="space-y-1.5 text-sm">
                    <li
                        v-for="w in preview.last_winners"
                        :key="w.year"
                        class="flex items-baseline gap-2"
                    >
                        <span class="w-10 shrink-0 tabular-nums text-zinc-500">{{ w.year }}</span>
                        <span
                            class="h-2 w-2 shrink-0 translate-y-[-1px] rounded-full"
                            :style="{ backgroundColor: w.color ?? NEUTRAL_DOT_COLOR }"
                        />
                        <Link
                            v-if="canOpenDriver && w.slug"
                            :href="route('drivers.show', w.slug)"
                            class="truncate text-zinc-200 transition hover:text-red-400"
                        >
                            {{ w.driver }}
                        </Link>
                        <span v-else class="truncate text-zinc-200">{{ w.driver }}</span>
                    </li>
                </ul>
            </div>

            <div v-if="preview.all_time?.length" class="min-w-0">
                <h3 class="mb-2 text-xs font-black uppercase tracking-wide text-zinc-500">Най-успешни тук</h3>
                <ul class="space-y-1.5 text-sm">
                    <li
                        v-for="row in preview.all_time"
                        :key="row.slug ?? row.name"
                        class="flex items-baseline gap-2"
                    >
                        <span class="w-5 shrink-0 font-bold tabular-nums text-zinc-500">{{ row.position }}</span>
                        <Link
                            v-if="canOpenDriver && row.slug"
                            :href="route('drivers.show', row.slug)"
                            class="truncate text-zinc-200 transition hover:text-red-400"
                        >
                            {{ row.name }}
                        </Link>
                        <span v-else class="truncate text-zinc-200">{{ row.name }}</span>
                        <span class="ml-auto shrink-0 tabular-nums text-zinc-500">{{ row.wins }} поб.</span>
                    </li>
                </ul>
            </div>
        </div>
    </section>
</template>
