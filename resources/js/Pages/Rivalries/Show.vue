<script setup>
import Card from '@/Components/UI/Card.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    rivalry: { type: Object, required: true },
    a: { type: Object, required: true },
    b: { type: Object, required: true },
    comparison: { type: Object, required: true },
});

const career = computed(() => props.comparison.career);
const h2h = computed(() => props.comparison.head_to_head);

const statRows = computed(() => [
    { label: 'Победи', a: career.value.a.wins, b: career.value.b.wins },
    { label: 'Подиуми', a: career.value.a.podiums, b: career.value.b.podiums },
    { label: 'Pole', a: career.value.a.poles, b: career.value.b.poles },
]);

const pct = (a, b) => {
    const total = a + b;
    return total > 0 ? Math.round((a / total) * 100) : 50;
};
const better = (a, b) => (a > b ? 'a' : b > a ? 'b' : 'tie');
</script>

<template>
    <Head :title="rivalry.title" />

    <PublicLayout>
        <Link :href="route('rivalries.index')" class="text-sm text-zinc-500 transition hover:text-zinc-300">← Всички съперничества</Link>

        <!-- Hero -->
        <section class="mt-3 rounded-2xl border border-zinc-800 bg-gradient-to-br from-red-950/30 via-zinc-950 to-black p-6 sm:p-8">
            <div class="flex items-center justify-center gap-4 sm:gap-8">
                <Link :href="route('drivers.show', a.slug)" class="text-center">
                    <img v-if="a.photo" :src="a.photo" :alt="a.name" loading="lazy" referrerpolicy="no-referrer" class="mx-auto h-20 w-20 rounded-full object-cover object-top sm:h-28 sm:w-28" />
                    <div v-else class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-zinc-800 text-3xl sm:h-28 sm:w-28">🏎️</div>
                    <div class="mt-2 font-bold text-white">{{ a.name }} {{ a.flag }}</div>
                </Link>
                <span class="font-display text-2xl font-black text-red-600 sm:text-4xl">VS</span>
                <Link :href="route('drivers.show', b.slug)" class="text-center">
                    <img v-if="b.photo" :src="b.photo" :alt="b.name" loading="lazy" referrerpolicy="no-referrer" class="mx-auto h-20 w-20 rounded-full object-cover object-top sm:h-28 sm:w-28" />
                    <div v-else class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-zinc-800 text-3xl sm:h-28 sm:w-28">🏎️</div>
                    <div class="mt-2 font-bold text-white">{{ b.name }} {{ b.flag }}</div>
                </Link>
            </div>
            <h1 class="mt-6 text-center font-display text-2xl font-black sm:text-3xl">{{ rivalry.title }}</h1>
            <p v-if="rivalry.era" class="text-center text-sm tabular-nums text-zinc-500">{{ rivalry.era }}</p>
            <p class="mx-auto mt-4 max-w-2xl text-center text-zinc-300">{{ rivalry.description }}</p>
        </section>

        <!-- Career bars -->
        <section class="mt-8">
            <h2 class="mb-4 font-display text-lg font-bold text-white">Кариерен баланс</h2>
            <div class="space-y-4">
                <div v-for="row in statRows" :key="row.label">
                    <div class="mb-1 flex items-center justify-between text-sm">
                        <span class="font-bold tabular-nums" :class="better(row.a, row.b) === 'a' ? 'text-red-500' : 'text-zinc-300'">{{ row.a }}</span>
                        <span class="text-xs uppercase tracking-wide text-zinc-500">{{ row.label }}</span>
                        <span class="font-bold tabular-nums" :class="better(row.a, row.b) === 'b' ? 'text-red-500' : 'text-zinc-300'">{{ row.b }}</span>
                    </div>
                    <div class="flex h-2 overflow-hidden rounded-full bg-zinc-800">
                        <div class="bg-red-600/80" :style="{ width: pct(row.a, row.b) + '%' }" />
                        <div class="bg-sky-500/70" :style="{ width: 100 - pct(row.a, row.b) + '%' }" />
                    </div>
                </div>
            </div>

            <Card v-if="h2h" muted class="mt-4 text-sm text-zinc-400">
                В {{ h2h.races_together }} общи състезания:
                квалификации <span class="font-bold text-white">{{ h2h.qualifying.a }}–{{ h2h.qualifying.b }}</span>,
                финиш <span class="font-bold text-white">{{ h2h.race.a }}–{{ h2h.race.b }}</span>.
            </Card>
        </section>

        <!-- Notable moments -->
        <section v-if="rivalry.moments.length" class="mt-8">
            <h2 class="mb-4 font-display text-lg font-bold text-white">Запомнящи се моменти</h2>
            <div class="space-y-3">
                <Card v-for="(m, i) in rivalry.moments" :key="i" class="flex gap-4">
                    <div class="shrink-0 font-display text-xl font-black tabular-nums text-red-600">{{ m.year }}</div>
                    <p class="text-zinc-300">{{ m.description }}</p>
                </Card>
            </div>
        </section>

        <div class="mt-8 text-center">
            <Link :href="route('compare.show', [a.slug, b.slug])" class="text-sm font-medium text-red-500 transition hover:text-red-400">
                Пълно сравнение на двамата →
            </Link>
        </div>
    </PublicLayout>
</template>
