<script setup>
import { hasRoute } from '@/utils/routes';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    // { rank, players, points, predictions } — null за гост или за никога неиграл.
    me: { type: Object, default: null },
});

const canOpenLeaderboard = computed(() => hasRoute('leaderboard'));

// Медал за подиума в лигата — при малко играчи третото място още значи нещо.
const medal = computed(() => ({ 1: '🥇', 2: '🥈', 3: '🥉' })[props.me?.rank] ?? null);
</script>

<template>
    <!-- Огледалото на predictionCta: то се показва при ЛИПСВАЩА прогноза, това —
         при подадена. Дотук влезлият виждаше същата страница като анонимния. -->
    <component
        :is="canOpenLeaderboard ? Link : 'div'"
        v-if="me"
        :href="canOpenLeaderboard ? route('leaderboard') : undefined"
        class="group flex flex-wrap items-center gap-x-6 gap-y-3 rounded-2xl border border-zinc-800 bg-zinc-900/60 p-4 transition duration-200"
        :class="canOpenLeaderboard ? 'hover:border-zinc-700' : ''"
    >
        <div class="flex items-baseline gap-2">
            <span v-if="medal" class="text-lg" aria-hidden="true">{{ medal }}</span>
            <span class="font-display text-3xl font-black leading-none tabular-nums text-white">
                <template v-if="me.rank">#{{ me.rank }}</template>
                <template v-else>—</template>
            </span>
            <span class="text-sm text-zinc-500">от {{ me.players }}</span>
        </div>

        <dl class="flex gap-6 text-sm">
            <div>
                <dt class="text-[11px] uppercase tracking-wide text-zinc-500">точки</dt>
                <dd class="font-display font-black tabular-nums text-white">{{ me.points }}</dd>
            </div>
            <div>
                <dt class="text-[11px] uppercase tracking-wide text-zinc-500">прогнози</dt>
                <dd class="font-display font-black tabular-nums text-white">{{ me.predictions }}</dd>
            </div>
        </dl>

        <span
            v-if="canOpenLeaderboard"
            class="ml-auto text-sm font-medium text-red-500 transition group-hover:text-red-400"
        >
            Класирането →
        </span>
    </component>
</template>
