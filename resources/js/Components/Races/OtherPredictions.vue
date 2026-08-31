<script setup>
import { hasRoute } from '@/utils/routes';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    predictions: { type: Array, default: () => [] },
});

const canOpenProfile = computed(() => hasRoute('profiles.show'));
</script>

<template>
    <!-- Показва се само след заключване (виж RaceController) — преди това би
         било подсказка. Лига без видими чужди прогнози е формуляр, не игра. -->
    <section v-if="predictions.length" class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-5">
        <h2 class="mb-1 font-display text-lg font-bold text-white">Какво прогнозираха останалите</h2>
        <p class="mb-4 text-xs text-zinc-500">Показва се след заключването на кръга.</p>

        <ul class="space-y-3">
            <li
                v-for="row in predictions"
                :key="row.user_id"
                class="rounded-lg border border-zinc-800 bg-black/30 p-3"
            >
                <div class="mb-1.5 flex items-baseline justify-between gap-2">
                    <Link
                        v-if="canOpenProfile && row.user_id"
                        :href="route('profiles.show', row.user_id)"
                        class="truncate font-semibold text-white transition hover:text-red-400"
                    >
                        {{ row.user }}
                    </Link>
                    <span v-else class="truncate font-semibold text-white">{{ row.user }}</span>

                    <span
                        v-if="row.points !== null"
                        class="shrink-0 text-sm font-bold tabular-nums text-red-500"
                    >{{ row.points }} т.</span>
                </div>

                <ol class="flex flex-wrap gap-x-3 gap-y-1 text-sm text-zinc-400">
                    <li v-for="(name, i) in row.podium" :key="i" class="flex items-baseline gap-1">
                        <span class="text-xs text-zinc-600">{{ ['🥇', '🥈', '🥉'][i] }}</span>
                        <span>{{ name ?? '—' }}</span>
                    </li>
                </ol>
            </li>
        </ul>
    </section>
</template>
