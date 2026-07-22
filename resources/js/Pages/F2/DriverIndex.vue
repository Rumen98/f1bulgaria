<script setup>
import FlagIcon from '@/Components/FlagIcon.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { podiumClass } from '@/utils/racing';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    season: Number,
    drivers: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Пилоти Формула 2" />

    <PublicLayout>
        <Link :href="route('f2')" class="text-sm text-zinc-500 transition hover:text-zinc-300">← Формула 2</Link>
        <h1 class="mb-6 mt-1 font-display text-2xl font-black sm:text-3xl">Пилоти F2 <span class="text-red-600">{{ season }}</span></h1>

        <EmptyState v-if="drivers.length === 0">Няма данни за пилоти.</EmptyState>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <Link
                v-for="d in drivers"
                :key="d.slug"
                :href="route('f2.drivers.show', d.slug)"
                class="flex items-center gap-3 rounded-xl border bg-zinc-900/60 p-4 transition hover:bg-zinc-900"
                :class="d.is_bulgarian ? 'border-emerald-500/40' : 'border-zinc-800 hover:border-zinc-600'"
            >
                <span class="w-7 text-center text-lg font-black tabular-nums" :class="podiumClass(d.position) || 'text-zinc-500'">{{ d.position ?? '—' }}</span>
                <div class="min-w-0 flex-1">
                    <div class="truncate font-semibold text-white"><FlagIcon :code="d.flag" class="mr-1" />{{ d.name }}</div>
                    <div class="truncate text-sm text-zinc-500">{{ d.team ?? '—' }}</div>
                </div>
                <span class="shrink-0 font-bold tabular-nums text-zinc-300">{{ d.points }}</span>
            </Link>
        </div>
    </PublicLayout>
</template>
