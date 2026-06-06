<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    season: Number,
    drivers: { type: Array, default: () => [] },
});

const posClass = (p) => ({ 1: 'text-amber-300', 2: 'text-zinc-300', 3: 'text-orange-400' })[p] ?? 'text-zinc-500';
</script>

<template>
    <Head title="Пилоти Формула 2" />

    <PublicLayout>
        <Link :href="route('f2')" class="text-sm text-zinc-500 transition hover:text-zinc-300">← Формула 2</Link>
        <h1 class="mb-6 mt-1 text-2xl font-black sm:text-3xl">Пилоти F2 <span class="text-red-600">{{ season }}</span></h1>

        <div v-if="drivers.length === 0" class="rounded-xl border border-dashed border-zinc-800 p-10 text-center text-zinc-500">
            Няма данни за пилоти.
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <Link
                v-for="d in drivers"
                :key="d.slug"
                :href="route('f2.drivers.show', d.slug)"
                class="flex items-center gap-3 rounded-xl border bg-zinc-900/60 p-4 transition hover:bg-zinc-900"
                :class="d.is_bulgarian ? 'border-emerald-500/40' : 'border-zinc-800 hover:border-zinc-600'"
            >
                <span class="w-7 text-center text-lg font-black tabular-nums" :class="posClass(d.position)">{{ d.position ?? '—' }}</span>
                <div class="min-w-0 flex-1">
                    <div class="truncate font-semibold text-white">{{ d.flag }} {{ d.name }}</div>
                    <div class="truncate text-sm text-zinc-500">{{ d.team ?? '—' }}</div>
                </div>
                <span class="shrink-0 font-bold tabular-nums text-zinc-300">{{ d.points }}</span>
            </Link>
        </div>
    </PublicLayout>
</template>
