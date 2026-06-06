<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    rivalries: { type: Array, default: () => [] },
    canCreate: { type: Boolean, default: false },
});
</script>

<template>
    <Head title="Съперничества" />

    <PublicLayout>
        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-black sm:text-3xl">Велики съперничества</h1>
                <p class="mt-1 text-zinc-400">Дуелите, които оформиха историята на Формула 1.</p>
            </div>

            <Link
                v-if="canCreate"
                :href="route('rivalries.create')"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-red-500"
            >
                + Създай дуел
            </Link>
            <Link
                v-else
                :href="route('login')"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-zinc-700 px-4 py-2 text-sm font-medium text-zinc-300 transition hover:border-red-600 hover:text-white"
            >
                🔒 Влез, за да създадеш свой дуел
            </Link>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <Link
                v-for="r in rivalries"
                :key="r.slug"
                :href="route('rivalries.show', r.slug)"
                class="group relative overflow-hidden rounded-2xl border border-zinc-800 bg-zinc-900/60 p-5 transition duration-200 hover:border-red-600/50 hover:bg-zinc-900"
            >
                <span v-if="r.is_featured" class="absolute right-3 top-3 rounded bg-red-600/20 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-red-400">Топ</span>
                <span v-else-if="r.is_custom" class="absolute right-3 top-3 rounded bg-sky-500/15 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-sky-400">Общност</span>

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
