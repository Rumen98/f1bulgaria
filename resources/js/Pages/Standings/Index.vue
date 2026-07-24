<script setup>
import TeamBrand from '@/Components/Team/TeamBrand.vue';
import TableShell from '@/Components/UI/TableShell.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { NEUTRAL_DOT_COLOR } from '@/utils/racing';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    season: Number,
    seasons: { type: Array, default: () => [] },
    drivers: Array,
    constructors: Array,
});

const tab = ref('drivers');

const goToSeason = (e) => {
    const year = Number(e.target.value);
    router.visit(route('standings.year', year));
};
</script>

<template>
    <Head title="Класиране">
        <meta head-key="description" name="description" content="Класиране във Формула 1 — пилоти и конструктори по сезони, точки, победи и подиуми." />
    </Head>

    <PublicLayout>
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <h1 class="font-display text-2xl font-black sm:text-3xl">Класиране <span class="text-red-600">{{ season }}</span></h1>
            <select
                v-if="seasons.length"
                :value="season"
                aria-label="Сезон"
                class="rounded-lg border border-zinc-800 bg-zinc-900 px-3 py-2 text-sm text-white focus:border-red-600 focus:outline-none focus:ring-1 focus:ring-red-600"
                @change="goToSeason"
            >
                <option v-for="y in seasons" :key="y" :value="y">{{ y }}</option>
            </select>
        </div>

        <div class="mb-6 inline-flex rounded-lg border border-zinc-800 bg-zinc-900/60 p-1">
            <button
                class="rounded-md px-4 py-2 text-sm font-medium transition duration-200"
                :class="tab === 'drivers' ? 'bg-red-600 text-white' : 'text-zinc-400 hover:text-white'"
                :aria-pressed="tab === 'drivers'"
                @click="tab = 'drivers'"
            >
                Пилоти
            </button>
            <button
                class="rounded-md px-4 py-2 text-sm font-medium transition duration-200"
                :class="tab === 'constructors' ? 'bg-red-600 text-white' : 'text-zinc-400 hover:text-white'"
                :aria-pressed="tab === 'constructors'"
                @click="tab = 'constructors'"
            >
                Конструктори
            </button>
        </div>

        <TableShell v-if="tab === 'drivers'">
            <table class="w-full whitespace-nowrap text-sm">
                <thead class="bg-zinc-900/80 text-left text-xs uppercase tracking-wide text-zinc-500">
                    <tr>
                        <th class="px-4 py-2.5 w-12">#</th>
                        <th class="px-4 py-2.5">Пилот</th>
                        <th class="px-4 py-2.5">Отбор</th>
                        <th class="px-4 py-2.5 text-center">Победи</th>
                        <th class="px-4 py-2.5 text-right">Точки</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800/60">
                    <tr v-for="row in drivers" :key="row.driver.id" class="bg-zinc-900/40 transition duration-200 hover:bg-zinc-800/40">
                        <td class="px-4 py-2.5 font-bold tabular-nums text-zinc-500">{{ row.position }}</td>
                        <td class="px-4 py-2.5 font-semibold text-white">
                            {{ row.driver.full_name }}
                            <span class="ml-1 text-xs font-normal text-zinc-500">{{ row.driver.code }}</span>
                        </td>
                        <td class="px-4 py-2.5 text-zinc-300">
                            <span class="inline-flex items-center gap-2">
                                <span
                                    class="h-3 w-3 rounded-full"
                                    :style="{ backgroundColor: row.driver.constructor?.color_hex ?? NEUTRAL_DOT_COLOR }"
                                />
                                {{ row.driver.constructor?.name ?? '—' }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-center tabular-nums text-zinc-300">{{ row.wins }}</td>
                        <td class="px-4 py-2.5 text-right font-bold tabular-nums text-white">{{ row.points }}</td>
                    </tr>
                </tbody>
            </table>
        </TableShell>

        <TableShell v-else>
            <table class="w-full whitespace-nowrap text-sm">
                <thead class="bg-zinc-900/80 text-left text-xs uppercase tracking-wide text-zinc-500">
                    <tr>
                        <th class="px-4 py-2.5 w-12">#</th>
                        <th class="px-4 py-2.5">Конструктор</th>
                        <th class="px-4 py-2.5 text-center">Победи</th>
                        <th class="px-4 py-2.5 text-right">Точки</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800/60">
                    <tr v-for="row in constructors" :key="row.constructor.id" class="bg-zinc-900/40 transition duration-200 hover:bg-zinc-800/40">
                        <td class="px-4 py-2.5 font-bold tabular-nums text-zinc-500">{{ row.position }}</td>
                        <td class="px-4 py-2.5 font-semibold text-white">
                            <span class="inline-flex items-center gap-2">
                                <span class="h-6 w-6 shrink-0">
                                    <TeamBrand :name="row.constructor.name" :slug="row.constructor.slug" :color="row.constructor.color_hex ?? NEUTRAL_DOT_COLOR" variant="emblem" />
                                </span>
                                {{ row.constructor.name_bg ?? row.constructor.name }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-center tabular-nums text-zinc-300">{{ row.wins }}</td>
                        <td class="px-4 py-2.5 text-right font-bold tabular-nums text-white">{{ row.points }}</td>
                    </tr>
                </tbody>
            </table>
        </TableShell>
    </PublicLayout>
</template>
