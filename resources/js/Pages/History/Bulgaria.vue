<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    hero: { type: Object, required: true },
    sections: { type: Array, default: () => [] },
});

const readingMinutes = computed(() => {
    const words = props.sections
        .flatMap((s) => s.paragraphs)
        .join(' ')
        .split(/\s+/)
        .filter(Boolean).length
        + props.hero.intro.split(/\s+/).filter(Boolean).length;
    return Math.max(1, Math.round(words / 180)); // ~180 думи/мин
});

const scrollTo = (id) => {
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
};
</script>

<template>

    <PublicLayout>
        <article class="mx-auto max-w-3xl">
            <Link :href="route('history')" class="text-sm text-zinc-500 transition hover:text-zinc-300">← Историята на F1</Link>

            <!-- Hero -->
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
                    <p class="mt-4 max-w-2xl text-zinc-300 sm:text-lg">{{ hero.intro }}</p>
                    <p class="mt-4 text-xs uppercase tracking-wide text-zinc-500">~{{ readingMinutes }} мин четене</p>
                </div>
            </header>

            <!-- Съдържание (TOC) -->
            <nav aria-label="Съдържание" class="mt-8 rounded-xl border border-zinc-800 bg-zinc-900/40 p-4">
                <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Съдържание</h2>
                <ul class="space-y-1">
                    <li v-for="s in sections" :key="s.id">
                        <button type="button" class="text-left text-sm text-zinc-300 transition hover:text-red-400" @click="scrollTo(s.id)">
                            {{ s.heading }}
                        </button>
                    </li>
                </ul>
            </nav>

            <!-- Секции -->
            <section v-for="s in sections" :id="s.id" :key="s.id" class="mt-10 scroll-mt-24">
                <h2 class="mb-4 border-l-4 border-red-600 pl-3 font-display text-2xl font-bold text-white">{{ s.heading }}</h2>
                <div class="space-y-4 leading-relaxed text-zinc-300">
                    <p v-for="(p, i) in s.paragraphs" :key="i">{{ p }}</p>
                </div>
            </section>

            <p class="mt-12 border-t border-zinc-800 pt-6 text-center text-sm text-zinc-500">
                Имаш спомен или поправка? Пиши ни — тази история се пише от общността.
            </p>
        </article>
    </PublicLayout>
</template>
