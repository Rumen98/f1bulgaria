<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    circuits: Array,
});
</script>

<template>
    <Head title="Писти" />

    <PublicLayout>
        <h1 class="mb-2 text-2xl font-black sm:text-3xl">Писти</h1>
        <p class="mb-6 text-sm text-zinc-500">История и all-time класиране за всяка писта в базата.</p>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Link
                v-for="c in circuits"
                :key="c.slug"
                :href="route('circuits.show', c.slug)"
                class="group rounded-xl border border-zinc-800 bg-zinc-900/60 p-5 transition duration-200 hover:border-red-600/50 hover:bg-zinc-900"
            >
                <div class="flex items-center justify-between">
                    <div>
                        <div class="font-bold text-white transition group-hover:text-red-400">{{ c.name }}</div>
                        <div class="text-sm text-zinc-500">{{ c.country }}</div>
                    </div>
                    <span v-if="c.has_track" class="text-xs text-zinc-600">🏁 track</span>
                </div>
            </Link>
        </div>
    </PublicLayout>
</template>
