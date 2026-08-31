<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    teaser: { type: Object, required: true },
});

const formatLap = (ms) => {
    const minutes = Math.floor(ms / 60000);
    const seconds = ((ms % 60000) / 1000).toFixed(3).padStart(6, '0');
    return `${minutes}:${seconds}`;
};

const MEDALS = ['🥇', '🥈', '🥉'];
</script>

<template>
    <!-- Хронометърът: пистата на уикенда + топ 3 — „Иван е дал 1:23, аз мога
         по-бързо" работи по-добре от всеки банер. -->
    <section class="mt-10 rounded-xl border border-zinc-800 bg-zinc-900/60 p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <div class="text-[11px] font-bold uppercase tracking-widest text-[#ff5a55]">
                    Хронометър · Пистата на уикенда
                </div>
                <h2 class="mt-1 font-display text-xl font-black text-white sm:text-2xl">
                    Карай {{ teaser.name }} този уикенд
                </h2>
            </div>
            <Link
                :href="`/game?track=${teaser.slug}`"
                class="rounded-lg bg-[#e10600] px-4 py-2.5 text-sm font-bold uppercase tracking-wider text-white transition hover:bg-[#ff0800]"
            >
                Карай →
            </Link>
        </div>

        <ol v-if="teaser.top.length" class="mt-4 grid gap-2 sm:grid-cols-3">
            <li
                v-for="(row, i) in teaser.top"
                :key="row.user_id"
                class="flex items-baseline justify-between gap-3 rounded-lg bg-black/30 px-3 py-2 text-sm"
            >
                <span class="truncate text-zinc-300">{{ MEDALS[i] }} {{ row.name }}</span>
                <span class="font-mono tabular-nums text-zinc-400">{{ formatLap(row.lap_ms) }}</span>
            </li>
        </ol>
        <p v-else class="mt-4 text-sm text-zinc-500">
            Още никой не е записал време тази седмица — бъди първи! 🏁
        </p>
    </section>
</template>
