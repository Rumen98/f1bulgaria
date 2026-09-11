<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { hasRoute } from '@/utils/routes';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    systems: { type: Array, default: () => [] },
});

const total = computed(() => props.systems.reduce((n, s) => n + s.topics.length, 0));

/**
 * По една проста рисунка на система — рубриката е за хора, които не четат
 * заглавия, а гледат икони. Формите са абстрактни нарочно: силует на болид би
 * обещал повече, отколкото страницата дава.
 */
const GLYPHS = {
    chassis: 'M4 30 C 14 14, 34 14, 44 30 M10 30 h28',
    power: 'M20 6 L10 26 h10 L18 42 L34 20 H22 Z',
    contact: 'M24 8 a16 16 0 1 0 0.1 0 M24 16 a8 8 0 1 0 0.1 0',
    data: 'M6 40 L18 26 L28 32 L42 10 M6 8 v34 h36',
};
</script>

<template>
    <PublicLayout>
        <article class="mx-auto max-w-4xl">
            <header class="relative overflow-hidden rounded-2xl border border-zinc-800 bg-gradient-to-br from-red-950/40 via-zinc-950 to-black p-8 sm:p-12">
                <svg viewBox="0 0 400 200" preserveAspectRatio="xMidYMid slice" aria-hidden="true" class="absolute inset-0 h-full w-full opacity-10">
                    <g stroke="#e10600" stroke-width="3" stroke-linecap="round" fill="none">
                        <path d="M-20 40 C 120 40, 180 120, 420 120" />
                        <path d="M-20 80 C 140 80, 200 160, 420 160" />
                        <path d="M-20 120 C 100 120, 160 30, 420 30" />
                    </g>
                </svg>

                <div class="relative">
                    <h1 class="font-display text-3xl font-black sm:text-5xl">Инженерството зад Формула 1</h1>
                    <p class="mt-4 max-w-2xl text-zinc-300 sm:text-lg">
                        Какво всъщност прави болида бърз — обяснено на български, за човек, който гледа състезанията,
                        но не е инженер. Без формули и без снизхождение.
                    </p>
                    <p class="mt-4 text-xs uppercase tracking-wide text-zinc-500">
                        {{ total }} {{ total === 1 ? 'тема' : 'теми' }} · регламентът от 2026
                    </p>
                </div>
            </header>

            <section v-for="system in systems" :key="system.key" class="mt-10">
                <div class="mb-4 flex items-center gap-3">
                    <svg viewBox="0 0 48 48" class="h-7 w-7 shrink-0" aria-hidden="true">
                        <path :d="GLYPHS[system.key]" fill="none" stroke="#e10600" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <h2 class="font-display text-xl font-bold text-white">{{ system.label }}</h2>
                </div>

                <ul class="grid gap-4 sm:grid-cols-2">
                    <li v-for="topic in system.topics" :key="topic.slug">
                        <Link
                            :href="route('engineering.show', topic.slug)"
                            class="flex h-full flex-col rounded-xl border border-zinc-800 bg-zinc-900/60 p-5 transition duration-200 hover:border-red-600/50 hover:bg-zinc-900"
                        >
                            <h3 class="font-display text-lg font-bold text-white">{{ topic.title }}</h3>
                            <p class="mt-2 grow text-sm leading-relaxed text-zinc-400">{{ topic.teaser }}</p>
                            <p v-if="topic.minutes" class="mt-3 text-xs uppercase tracking-wide text-zinc-600">
                                ~{{ topic.minutes }} мин четене
                            </p>
                        </Link>
                    </li>
                </ul>
            </section>

            <p v-if="hasRoute('terminology')" class="mt-12 border-t border-zinc-800 pt-6 text-sm text-zinc-500">
                Срещаш непознат термин?
                <Link :href="route('terminology')" class="text-red-500 transition hover:text-red-400">Речникът</Link>
                обяснява всички съкращения на български.
            </p>
        </article>
    </PublicLayout>
</template>
