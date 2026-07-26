<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    hero: { type: Object, required: true },
    eras: { type: Array, default: () => [] },
    legends: { type: Array, default: () => [] },
});

const readingMinutes = computed(() => {
    const words = props.eras.map((e) => e.body).join(' ').split(/\s+/).filter(Boolean).length;
    return Math.max(1, Math.round(words / 180));
});
</script>

<template>

    <PublicLayout>
        <article class="mx-auto max-w-3xl">
            <Link :href="route('history')" class="text-sm text-zinc-500 transition hover:text-zinc-300">← Историята на F1</Link>

            <header class="relative mt-3 overflow-hidden rounded-2xl border border-zinc-800 bg-gradient-to-br from-red-950/40 via-zinc-950 to-black p-8 sm:p-12">
                <svg viewBox="0 0 400 200" preserveAspectRatio="xMidYMid slice" aria-hidden="true" class="absolute inset-0 h-full w-full opacity-10">
                    <g stroke="#e10600" stroke-width="3" stroke-linecap="round">
                        <line x1="-20" y1="50" x2="160" y2="50" />
                        <line x1="-20" y1="100" x2="240" y2="100" />
                        <line x1="-20" y1="150" x2="120" y2="150" />
                    </g>
                </svg>
                <div class="relative">
                    <h1 class="font-display text-3xl font-black sm:text-5xl">{{ hero.title }}</h1>
                    <p class="mt-4 text-zinc-300 sm:text-lg">{{ hero.intro }}</p>
                    <p class="mt-4 text-xs uppercase tracking-wide text-zinc-500">~{{ readingMinutes }} мин четене</p>
                </div>
            </header>

            <!-- Ери — вертикална времева линия -->
            <div class="mt-10 space-y-8">
                <section v-for="(era, i) in eras" :key="i" class="relative border-l-2 border-zinc-800 pl-6">
                    <span class="absolute -left-[9px] top-1 h-4 w-4 rounded-full border-2 border-red-600 bg-zinc-950" />
                    <div class="text-xs font-bold uppercase tracking-wider text-red-500">{{ era.years }}</div>
                    <h2 class="mt-1 font-display text-xl font-bold text-white">{{ era.title }}</h2>
                    <p class="mt-2 leading-relaxed text-zinc-300">{{ era.body }}</p>
                </section>
            </div>

            <!-- Легенди -->
            <section v-if="legends.length" class="mt-12">
                <h2 class="mb-4 font-display text-2xl font-bold text-white">Легендите</h2>
                <div class="flex flex-wrap gap-2">
                    <Link
                        v-for="l in legends"
                        :key="l.slug"
                        :href="route('drivers.show', l.slug)"
                        class="rounded-full border border-zinc-700 bg-zinc-900/60 px-4 py-2 text-sm font-medium text-zinc-200 transition hover:border-red-600 hover:text-white"
                    >
                        {{ l.name }}
                    </Link>
                </div>
            </section>

            <p class="mt-12 border-t border-zinc-800 pt-6 text-center text-sm text-zinc-500">{{ hero.source }}</p>
        </article>
    </PublicLayout>
</template>
