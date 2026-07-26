<script setup>
import Card from '@/Components/UI/Card.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { computed, ref } from 'vue';

const props = defineProps({
    categories: { type: Object, default: () => ({}) },
    terms: { type: Array, default: () => [] },
});

const search = ref('');
const activeCat = ref('all');

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.terms
        .filter((t) => activeCat.value === 'all' || t.category === activeCat.value)
        .filter((t) => !q || t.term_bg.toLowerCase().includes(q) || t.term_en.toLowerCase().includes(q) || t.definition_bg.toLowerCase().includes(q))
        .slice()
        .sort((a, b) => a.term_bg.localeCompare(b.term_bg, 'bg'));
});
</script>

<template>
    <!-- meta/og таговете идват сървърно от App\Support\Seo (виж app.blade.php). -->

    <PublicLayout>
        <h1 class="mb-2 font-display text-2xl font-black sm:text-3xl">Речник на Формула 1</h1>
        <p class="mb-6 text-zinc-400">Термините от света на Формула 1 — обяснени на български.</p>

        <input
            v-model="search"
            type="search"
            placeholder="Търси термин…"
            aria-label="Търсене в речника"
            class="mb-4 w-full rounded-lg border border-zinc-800 bg-zinc-900 px-4 py-2.5 text-white placeholder-zinc-500 focus:border-red-600 focus:outline-none focus:ring-1 focus:ring-red-600"
        />

        <div class="mb-6 flex flex-wrap gap-2">
            <button
                type="button"
                class="rounded-full px-3 py-2 text-xs font-semibold transition"
                :class="activeCat === 'all' ? 'bg-red-600 text-white' : 'bg-zinc-900/60 text-zinc-400 hover:text-zinc-200'"
                :aria-pressed="activeCat === 'all'"
                @click="activeCat = 'all'"
            >
                Всички
            </button>
            <button
                v-for="(label, key) in categories"
                :key="key"
                type="button"
                class="rounded-full px-3 py-2 text-xs font-semibold transition"
                :class="activeCat === key ? 'bg-red-600 text-white' : 'bg-zinc-900/60 text-zinc-400 hover:text-zinc-200'"
                :aria-pressed="activeCat === key"
                @click="activeCat = key"
            >
                {{ label }}
            </button>
        </div>

        <EmptyState v-if="filtered.length === 0">
            Няма намерени термини.
        </EmptyState>

        <div v-else class="grid gap-3 sm:grid-cols-2">
            <Card
                v-for="(t, i) in filtered"
                :key="i"
                :id="t.term_en.toLowerCase().replace(/[^a-z0-9]+/g, '-')"
                class="scroll-mt-24"
            >
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="font-bold text-white">{{ t.term_bg }}</h2>
                    <span class="shrink-0 text-xs uppercase tracking-wide text-zinc-500">{{ t.term_en }}</span>
                </div>
                <p class="mt-1.5 text-sm leading-relaxed text-zinc-400">{{ t.definition_bg }}</p>
                <span class="mt-2 inline-block rounded bg-zinc-800/60 px-2 py-0.5 text-xs uppercase tracking-wide text-zinc-500">
                    {{ categories[t.category] }}
                </span>
            </Card>
        </div>
    </PublicLayout>
</template>
