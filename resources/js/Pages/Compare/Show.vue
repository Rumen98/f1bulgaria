<script setup>
import FlagIcon from '@/Components/FlagIcon.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import TableShell from '@/Components/UI/TableShell.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    a: { type: Object, required: true },
    b: { type: Object, required: true },
    comparison: { type: Object, required: true },
});

const career = computed(() => props.comparison.career);
const era = computed(() => props.comparison.era_overlap);
const h2h = computed(() => props.comparison.head_to_head);
const circuits = computed(() => props.comparison.common_circuits ?? []);

const statRows = computed(() => [
    { label: 'Победи', a: career.value.a.wins, b: career.value.b.wins },
    { label: 'Подиуми', a: career.value.a.podiums, b: career.value.b.podiums },
    { label: 'Pole позиции', a: career.value.a.poles, b: career.value.b.poles },
    { label: 'Старта', a: career.value.a.races, b: career.value.b.races },
    { label: 'Win rate %', a: career.value.a.win_rate, b: career.value.b.win_rate },
]);

const pct = (a, b) => {
    const total = a + b;
    return total > 0 ? Math.round((a / total) * 100) : 50;
};

const better = (a, b) => (a > b ? 'a' : b > a ? 'b' : 'tie');

// За common circuits: по-малка позиция е по-добра (null = не е финиширал).
const betterResult = (a, b) => {
    if (a === null && b === null) {
        return 'tie';
    }
    if (a === null) {
        return 'b';
    }
    if (b === null) {
        return 'a';
    }
    return a < b ? 'a' : b < a ? 'b' : 'tie';
};
</script>

<template>
    <Head :title="`${a.name} срещу ${b.name}`" />

    <PublicLayout>
        <h1 class="sr-only">{{ a.name }} срещу {{ b.name }}</h1>

        <Link :href="route('compare.index')" class="text-sm text-zinc-500 transition hover:text-zinc-300">← Ново сравнение</Link>

        <!-- Hero split -->
        <section class="mt-3 grid grid-cols-[1fr_auto_1fr] items-stretch gap-2 sm:gap-4">
            <div
                v-for="(d, i) in [a, b]"
                :key="d.slug"
                class="rounded-2xl border border-zinc-800 p-4 sm:p-6"
                :class="i === 1 ? 'order-3 text-right' : 'order-1'"
                :style="{ background: `linear-gradient(${i === 1 ? 250 : 110}deg, ${d.color_hex}33, #0a0a0a 65%)` }"
            >
                <div class="flex items-center gap-3" :class="i === 1 ? 'flex-row-reverse' : ''">
                    <img
                        v-if="d.photo"
                        :src="d.photo"
                        :alt="d.name"
                        loading="lazy"
                        referrerpolicy="no-referrer"
                        class="h-16 w-16 rounded-full object-cover object-top sm:h-24 sm:w-24"
                    />
                    <div v-else class="flex h-16 w-16 items-center justify-center rounded-full bg-zinc-800 text-2xl sm:h-24 sm:w-24">🏎️</div>
                    <div>
                        <div class="font-display text-xl font-black sm:text-2xl">{{ d.name }} <FlagIcon :code="d.flag" /></div>
                        <div class="text-sm text-zinc-400">{{ d.team ?? '—' }}</div>
                        <div class="mt-1 text-xs tabular-nums text-zinc-500">{{ career[i === 0 ? 'a' : 'b'].first_year }}–{{ career[i === 0 ? 'a' : 'b'].last_year }}</div>
                    </div>
                </div>
            </div>
            <div class="order-2 flex items-center justify-center font-display text-lg font-black text-red-600 sm:text-2xl">VS</div>
        </section>

        <!-- Career stats -->
        <section class="mt-8">
            <h2 class="mb-4 font-display text-lg font-bold text-white">Кариерни числа</h2>
            <div class="space-y-4">
                <div v-for="row in statRows" :key="row.label">
                    <div class="mb-1 flex items-center justify-between text-sm">
                        <span class="font-bold tabular-nums" :class="better(row.a, row.b) === 'a' ? 'text-red-500' : 'text-zinc-300'">{{ row.a }}</span>
                        <span class="text-xs uppercase tracking-wide text-zinc-500">{{ row.label }}</span>
                        <span class="font-bold tabular-nums" :class="better(row.a, row.b) === 'b' ? 'text-sky-400' : 'text-zinc-300'">{{ row.b }}</span>
                    </div>
                    <div class="flex h-2 overflow-hidden rounded-full bg-zinc-800">
                        <div class="bg-red-600/80" :style="{ width: pct(row.a, row.b) + '%' }" />
                        <div class="bg-sky-500/70" :style="{ width: 100 - pct(row.a, row.b) + '%' }" />
                    </div>
                </div>
            </div>
        </section>

        <!-- Era overlap + head-to-head -->
        <section class="mt-8">
            <h2 class="mb-4 font-display text-lg font-bold text-white">Един срещу друг</h2>
            <div v-if="era && h2h" class="rounded-2xl border border-zinc-800 bg-zinc-900/40 p-5">
                <p class="mb-4 text-sm text-zinc-400">
                    Карали по едно и също време <span class="font-bold text-white">{{ era.seasons_count }}</span>
                    {{ era.seasons_count === 1 ? 'сезон' : 'сезона' }} ({{ era.start_year }}–{{ era.end_year }}),
                    <span class="font-bold text-white">{{ h2h.races_together }}</span> общи състезания.
                </p>

                <div class="space-y-4">
                    <div>
                        <div class="mb-1 flex justify-between text-sm text-zinc-300">
                            <span class="font-bold tabular-nums">{{ h2h.qualifying.a }}</span>
                            <span class="text-xs uppercase tracking-wide text-zinc-500">Квалификация (по-добър старт)</span>
                            <span class="font-bold tabular-nums">{{ h2h.qualifying.b }}</span>
                        </div>
                        <div class="flex h-2 overflow-hidden rounded-full bg-zinc-800">
                            <div class="bg-red-600/80" :style="{ width: pct(h2h.qualifying.a, h2h.qualifying.b) + '%' }" />
                            <div class="bg-sky-500/70" :style="{ width: 100 - pct(h2h.qualifying.a, h2h.qualifying.b) + '%' }" />
                        </div>
                    </div>
                    <div>
                        <div class="mb-1 flex justify-between text-sm text-zinc-300">
                            <span class="font-bold tabular-nums">{{ h2h.race.a }}</span>
                            <span class="text-xs uppercase tracking-wide text-zinc-500">Състезание (по-добър финиш)</span>
                            <span class="font-bold tabular-nums">{{ h2h.race.b }}</span>
                        </div>
                        <div class="flex h-2 overflow-hidden rounded-full bg-zinc-800">
                            <div class="bg-red-600/80" :style="{ width: pct(h2h.race.a, h2h.race.b) + '%' }" />
                            <div class="bg-sky-500/70" :style="{ width: 100 - pct(h2h.race.a, h2h.race.b) + '%' }" />
                        </div>
                    </div>
                    <div class="flex justify-between text-sm text-zinc-400">
                        <span class="tabular-nums">{{ h2h.dnfs.a }} отпадания</span>
                        <span class="text-xs uppercase tracking-wide text-zinc-500">DNF</span>
                        <span class="tabular-nums">{{ h2h.dnfs.b }} отпадания</span>
                    </div>
                </div>
            </div>
            <EmptyState v-else>
                Двамата не са се състезавали по едно и също време — няма пряк head-to-head.
            </EmptyState>
        </section>

        <!-- Common circuits -->
        <section v-if="circuits.length" class="mt-8">
            <h2 class="mb-4 font-display text-lg font-bold text-white">Общи писти</h2>
            <TableShell>
                <table class="w-full text-sm">
                    <thead class="bg-zinc-900/80 text-xs uppercase tracking-wide text-zinc-500">
                        <tr>
                            <th class="px-4 py-2.5 text-left">Писта</th>
                            <th class="px-4 py-2.5 text-right">{{ a.name }}</th>
                            <th class="px-4 py-2.5 text-right">{{ b.name }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-800/60">
                        <tr v-for="c in circuits" :key="c.circuit_slug">
                            <td class="px-4 py-2.5 font-medium text-zinc-200">{{ c.circuit }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums" :class="betterResult(c.a_best, c.b_best) === 'a' ? 'font-bold text-red-500' : 'text-zinc-400'">
                                {{ c.a_best ? 'P' + c.a_best : '—' }}
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums" :class="betterResult(c.a_best, c.b_best) === 'b' ? 'font-bold text-sky-400' : 'text-zinc-400'">
                                {{ c.b_best ? 'P' + c.b_best : '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="bg-zinc-900/40 px-4 py-2 text-xs text-zinc-600">Най-добро класиране на всеки пилот на пистата.</p>
            </TableShell>
        </section>
    </PublicLayout>
</template>
