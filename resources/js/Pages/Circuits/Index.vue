<script setup>
import CircuitCard from '@/Components/Circuit/CircuitCard.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    circuits: { type: Array, default: () => [] },
    activeFilter: { type: String, default: 'all' },
    counts: { type: Object, default: () => ({ all: 0, active: 0, historical: 0 }) },
});

const active = computed(() => props.circuits.filter((c) => c.is_active));
const historical = computed(() => props.circuits.filter((c) => !c.is_active));

const tabs = [
    { key: 'all', label: 'Всички' },
    { key: 'active', label: 'Само активни' },
    { key: 'historical', label: 'Само исторически' },
];
</script>

<template>
    <Head title="Писти" />

    <PublicLayout>
        <h1 class="mb-2 font-display text-2xl font-black sm:text-3xl">Писти</h1>
        <p class="mb-6 text-sm text-zinc-500">История и all-time класиране за всяка писта в базата.</p>

        <!-- Филтър -->
        <div class="mb-6 flex flex-wrap gap-2">
            <Link
                v-for="t in tabs"
                :key="t.key"
                :href="route('circuits.index', t.key === 'all' ? {} : { filter: t.key })"
                class="rounded-full border px-3 py-2 text-sm font-medium transition duration-200"
                :class="activeFilter === t.key ? 'border-red-600 bg-red-600 text-white' : 'border-zinc-800 bg-zinc-900/60 text-zinc-400 hover:text-white'"
                :aria-pressed="activeFilter === t.key"
            >
                {{ t.label }} <span class="tabular-nums opacity-60">{{ counts[t.key] }}</span>
            </Link>
        </div>

        <!-- При „Всички" — две секции; иначе единичен грид -->
        <template v-if="activeFilter === 'all'">
            <section v-if="active.length" class="mb-8">
                <h2 class="mb-3 font-display text-lg font-bold text-white">Активни писти</h2>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <CircuitCard v-for="c in active" :key="c.slug" :circuit="c" />
                </div>
            </section>
            <section v-if="historical.length">
                <h2 class="mb-3 font-display text-lg font-bold text-white">Исторически писти</h2>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <CircuitCard v-for="c in historical" :key="c.slug" :circuit="c" />
                </div>
            </section>
        </template>
        <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <CircuitCard v-for="c in circuits" :key="c.slug" :circuit="c" />
        </div>
    </PublicLayout>
</template>
