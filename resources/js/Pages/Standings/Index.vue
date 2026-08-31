<script setup>
import TeamBrand from '@/Components/Team/TeamBrand.vue';
import SeasonSelect from '@/Components/UI/SeasonSelect.vue';
import TableShell from '@/Components/UI/TableShell.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { NEUTRAL_DOT_COLOR } from '@/utils/racing';
import { hasRoute } from '@/utils/routes';
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// Класирането е входна точка към профилите — без тези линкове таблицата е
// сляпа улица. Guard-овете пазят исторически сезони, където `constructor`
// идва през whenLoaded и slug-ът може да липсва.
const canOpenDriver = computed(() => hasRoute('drivers.show'));
const canOpenTeam = computed(() => hasRoute('teams.show'));

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
    <!-- meta/og таговете идват сървърно от App\Support\Seo (виж app.blade.php). -->

    <PublicLayout>
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <h1 class="font-display text-2xl font-black sm:text-3xl">Класиране <span class="text-red-600">{{ season }}</span></h1>
            <SeasonSelect v-if="seasons.length" :seasons="seasons" :selected="season" @change="goToSeason" />
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
                    <!-- „Отбор" и „Победи" падат под sm: на 360px точките иначе
                         стартират извън екрана, а те са целият смисъл на екрана. -->
                    <tr>
                        <th class="px-2 py-2.5 w-10 sm:px-4 sm:w-12">#</th>
                        <th class="px-2 py-2.5 sm:px-4">Пилот</th>
                        <th class="hidden px-4 py-2.5 sm:table-cell">Отбор</th>
                        <th class="hidden px-4 py-2.5 text-center sm:table-cell">Победи</th>
                        <th class="px-2 py-2.5 text-right sm:px-4">Точки</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800/60">
                    <tr v-for="row in drivers" :key="row.driver.id" class="bg-zinc-900/40 transition duration-200 hover:bg-zinc-800/40">
                        <td class="px-2 py-2.5 font-bold tabular-nums text-zinc-500 sm:px-4">{{ row.position }}</td>
                        <td class="px-2 py-2.5 font-semibold text-white sm:px-4">
                            <Link
                                v-if="canOpenDriver && row.driver.slug"
                                :href="route('drivers.show', row.driver.slug)"
                                class="transition hover:text-red-400"
                            >
                                {{ row.driver.full_name }}
                            </Link>
                            <span v-else>{{ row.driver.full_name }}</span>
                            <span class="ml-1 text-xs font-normal text-zinc-500">{{ row.driver.code }}</span>
                            <!-- Отборът се скрива като колона, но остава тук като
                                 подред — на телефон информацията не се губи. -->
                            <span v-if="row.driver.constructor" class="mt-0.5 flex items-center gap-1.5 text-xs font-normal text-zinc-500 sm:hidden">
                                <span
                                    class="h-2 w-2 shrink-0 rounded-full"
                                    :style="{ backgroundColor: row.driver.constructor.color_hex ?? NEUTRAL_DOT_COLOR }"
                                />
                                {{ row.driver.constructor.name }}
                            </span>
                        </td>
                        <td class="hidden px-4 py-2.5 text-zinc-300 sm:table-cell">
                            <span class="inline-flex items-center gap-2">
                                <span
                                    class="h-3 w-3 rounded-full"
                                    :style="{ backgroundColor: row.driver.constructor?.color_hex ?? NEUTRAL_DOT_COLOR }"
                                />
                                <Link
                                    v-if="canOpenTeam && row.driver.constructor?.slug"
                                    :href="route('teams.show', row.driver.constructor.slug)"
                                    class="transition hover:text-red-400"
                                >
                                    {{ row.driver.constructor.name }}
                                </Link>
                                <span v-else>{{ row.driver.constructor?.name ?? '—' }}</span>
                            </span>
                        </td>
                        <td class="hidden px-4 py-2.5 text-center tabular-nums text-zinc-300 sm:table-cell">{{ row.wins }}</td>
                        <td class="px-2 py-2.5 text-right font-bold tabular-nums text-white sm:px-4">{{ row.points }}</td>
                    </tr>
                </tbody>
            </table>
        </TableShell>

        <TableShell v-else>
            <table class="w-full whitespace-nowrap text-sm">
                <thead class="bg-zinc-900/80 text-left text-xs uppercase tracking-wide text-zinc-500">
                    <tr>
                        <th class="px-2 py-2.5 w-10 sm:px-4 sm:w-12">#</th>
                        <th class="px-2 py-2.5 sm:px-4">Конструктор</th>
                        <th class="hidden px-4 py-2.5 text-center sm:table-cell">Победи</th>
                        <th class="px-2 py-2.5 text-right sm:px-4">Точки</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800/60">
                    <tr v-for="row in constructors" :key="row.constructor.id" class="bg-zinc-900/40 transition duration-200 hover:bg-zinc-800/40">
                        <td class="px-2 py-2.5 font-bold tabular-nums text-zinc-500 sm:px-4">{{ row.position }}</td>
                        <td class="px-2 py-2.5 font-semibold text-white sm:px-4">
                            <Link
                                v-if="canOpenTeam && row.constructor.slug"
                                :href="route('teams.show', row.constructor.slug)"
                                class="inline-flex items-center gap-2 transition hover:text-red-400"
                            >
                                <span class="h-6 w-6 shrink-0">
                                    <TeamBrand :name="row.constructor.name" :slug="row.constructor.slug" :color="row.constructor.color_hex ?? NEUTRAL_DOT_COLOR" variant="emblem" />
                                </span>
                                {{ row.constructor.name_bg ?? row.constructor.name }}
                            </Link>
                            <span v-else class="inline-flex items-center gap-2">
                                <span class="h-6 w-6 shrink-0">
                                    <TeamBrand :name="row.constructor.name" :slug="row.constructor.slug" :color="row.constructor.color_hex ?? NEUTRAL_DOT_COLOR" variant="emblem" />
                                </span>
                                {{ row.constructor.name_bg ?? row.constructor.name }}
                            </span>
                        </td>
                        <td class="hidden px-4 py-2.5 text-center tabular-nums text-zinc-300 sm:table-cell">{{ row.wins }}</td>
                        <td class="px-2 py-2.5 text-right font-bold tabular-nums text-white sm:px-4">{{ row.points }}</td>
                    </tr>
                </tbody>
            </table>
        </TableShell>
    </PublicLayout>
</template>
