<script setup>
import Card from '@/Components/UI/Card.vue';
import { computed } from 'vue';

const props = defineProps({
    records: { type: Object, required: true },
});

// Картички „брой пъти" (пилот + count).
const countCards = computed(() => [
    { icon: '🏆', label: 'Най-много победи', rec: props.records.most_wins },
    { icon: '⏱️', label: 'Най-много pole', rec: props.records.most_poles },
    { icon: '🔥', label: 'Най-много най-бързи обиколки', rec: props.records.most_fastest_laps },
    { icon: '🔁', label: 'Най-дълга серия победи', rec: props.records.longest_winning_streak },
]);

const firstWinner = computed(() => props.records.first_winner);
const latestWinner = computed(() => props.records.latest_winner);
const poleConv = computed(() => props.records.pole_to_win_conversion_rate);
const avgGrid = computed(() => props.records.avg_winner_starting_position);
</script>

<template>
    <div>
        <h2 class="mb-3 font-display text-lg font-bold text-white">Рекорди на пистата</h2>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Card v-for="card in countCards" :key="card.label">
                <div class="text-xs uppercase tracking-wide text-zinc-500"><span aria-hidden="true">{{ card.icon }}</span> {{ card.label }}</div>
                <div v-if="card.rec" class="mt-1">
                    <div class="font-bold text-white">{{ card.rec.name }}</div>
                    <div class="text-sm tabular-nums text-red-500">{{ card.rec.count }}×</div>
                </div>
                <div v-else class="mt-1 text-sm text-zinc-500">—</div>
            </Card>
        </div>

        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Card>
                <div class="text-xs uppercase tracking-wide text-zinc-500"><span aria-hidden="true">🥇</span> Първи победител</div>
                <div v-if="firstWinner" class="mt-1">
                    <div class="font-bold text-white">{{ firstWinner.driver }}</div>
                    <div class="text-sm tabular-nums text-zinc-400">{{ firstWinner.year }}</div>
                </div>
                <div v-else class="mt-1 text-sm text-zinc-500">—</div>
            </Card>

            <Card>
                <div class="text-xs uppercase tracking-wide text-zinc-500"><span aria-hidden="true">🆕</span> Последен победител</div>
                <div v-if="latestWinner" class="mt-1">
                    <div class="font-bold text-white">{{ latestWinner.driver }}</div>
                    <div class="text-sm tabular-nums text-zinc-400">{{ latestWinner.year }}</div>
                </div>
                <div v-else class="mt-1 text-sm text-zinc-500">—</div>
            </Card>

            <Card>
                <div class="text-xs uppercase tracking-wide text-zinc-500"><span aria-hidden="true">🎯</span> Pole → победа</div>
                <div v-if="poleConv !== null && poleConv !== undefined" class="mt-1">
                    <div class="font-display text-2xl font-black tabular-nums text-white">{{ poleConv }}%</div>
                    <div class="text-xs text-zinc-500">конверсия от pole</div>
                </div>
                <div v-else class="mt-1 text-sm text-zinc-500">—</div>
            </Card>

            <Card>
                <div class="text-xs uppercase tracking-wide text-zinc-500"><span aria-hidden="true">📊</span> Средна стартова поз. на победителя</div>
                <div v-if="avgGrid !== null && avgGrid !== undefined" class="mt-1">
                    <div class="font-display text-2xl font-black tabular-nums text-white">P{{ avgGrid }}</div>
                </div>
                <div v-else class="mt-1 text-sm text-zinc-500">—</div>
            </Card>
        </div>
    </div>
</template>
