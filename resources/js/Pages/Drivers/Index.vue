<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    season: Number,
    drivers: Array,
});
</script>

<template>
    <Head title="Пилоти" />

    <PublicLayout>
        <h1 class="mb-6 text-2xl font-black sm:text-3xl">Пилоти <span class="text-red-600">{{ season }}</span></h1>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Link
                v-for="d in drivers"
                :key="d.slug"
                :href="route('drivers.show', d.slug)"
                class="group relative flex items-center gap-4 overflow-hidden rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 transition duration-200 hover:border-zinc-600 hover:bg-zinc-900"
            >
                <div class="absolute inset-y-0 left-0 w-1" :style="{ backgroundColor: d.color_hex }" />
                <div class="w-10 text-center text-2xl font-black tabular-nums text-zinc-600 group-hover:text-zinc-400">{{ d.number ?? '—' }}</div>
                <div class="min-w-0 flex-1">
                    <div class="truncate font-bold text-white">
                        <span v-if="d.flag">{{ d.flag }} </span>{{ d.name }}
                    </div>
                    <div class="truncate text-sm text-zinc-500">{{ d.team ?? '—' }}</div>
                </div>
                <div class="text-right">
                    <div class="font-bold tabular-nums text-white">{{ d.points }}</div>
                    <div v-if="d.position" class="text-xs text-zinc-500">P{{ d.position }}</div>
                </div>
            </Link>
        </div>
    </PublicLayout>
</template>
