<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    rivalries: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Съперничества" />

    <PublicLayout>
        <h1 class="mb-2 text-2xl font-black sm:text-3xl">Велики съперничества</h1>
        <p class="mb-6 text-zinc-400">Дуелите, които оформиха историята на Формула 1.</p>

        <div class="grid gap-4 sm:grid-cols-2">
            <Link
                v-for="r in rivalries"
                :key="r.slug"
                :href="route('rivalries.show', r.slug)"
                class="group relative overflow-hidden rounded-2xl border border-zinc-800 bg-zinc-900/60 p-5 transition duration-200 hover:border-red-600/50 hover:bg-zinc-900"
            >
                <span v-if="r.is_featured" class="absolute right-3 top-3 rounded bg-red-600/20 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-red-400">Топ</span>

                <div class="flex items-center gap-3">
                    <img v-if="r.one.photo" :src="r.one.photo" :alt="r.one.name" loading="lazy" referrerpolicy="no-referrer" class="h-14 w-14 rounded-full object-cover object-top" />
                    <div v-else class="flex h-14 w-14 items-center justify-center rounded-full bg-zinc-800">🏎️</div>
                    <span class="text-lg font-black text-red-600">VS</span>
                    <img v-if="r.two.photo" :src="r.two.photo" :alt="r.two.name" loading="lazy" referrerpolicy="no-referrer" class="h-14 w-14 rounded-full object-cover object-top" />
                    <div v-else class="flex h-14 w-14 items-center justify-center rounded-full bg-zinc-800">🏎️</div>
                </div>

                <h2 class="mt-4 text-lg font-bold text-white">{{ r.title }}</h2>
                <p v-if="r.era" class="text-xs tabular-nums text-zinc-500">{{ r.era }}</p>
                <p class="mt-2 line-clamp-3 text-sm text-zinc-400">{{ r.description }}</p>
            </Link>
        </div>

        <div v-if="rivalries.length === 0" class="rounded-xl border border-dashed border-zinc-800 p-10 text-center text-zinc-500">
            Все още няма добавени съперничества.
        </div>
    </PublicLayout>
</template>
