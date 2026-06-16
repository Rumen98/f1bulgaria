<script setup>
import TeamBrand from '@/Components/Team/TeamBrand.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    season: Number,
    teams: Array,
});

const tab = ref('active');
const search = ref('');

const tabs = [
    { key: 'active', label: 'Активни' },
    { key: 'legends', label: 'Легенди' },
    { key: 'all', label: 'Всички' },
];

const filtered = computed(() => {
    let list = props.teams ?? [];

    if (tab.value === 'active') {
        list = list.filter((t) => t.is_active);
    } else if (tab.value === 'legends') {
        list = list.filter((t) => !t.is_active);
    }

    const q = search.value.trim().toLowerCase();
    if (q) {
        list = list.filter((t) => t.name.toLowerCase().includes(q));
    }

    return list;
});
</script>

<template>
    <Head title="Отбори">
        <meta head-key="description" name="description" content="Всички отбори (конструктори) от Формула 1 — статистика, пилоти, титли и история. Българският справочник за отборите във F1." />
    </Head>

    <PublicLayout>
        <h1 class="mb-6 text-2xl font-black sm:text-3xl">Отбори</h1>

        <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex gap-2">
                <button
                    v-for="t in tabs"
                    :key="t.key"
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-semibold transition"
                    :class="tab === t.key ? 'bg-red-600 text-white' : 'bg-zinc-900/60 text-zinc-400 hover:text-zinc-200'"
                    @click="tab = t.key"
                >
                    {{ t.label }}
                </button>
            </div>
            <input
                v-model="search"
                type="search"
                placeholder="Търси отбор…"
                class="w-full rounded-lg border border-zinc-800 bg-zinc-900/60 px-4 py-2 text-sm text-white placeholder-zinc-500 focus:border-red-600 focus:outline-none sm:w-64"
            />
        </div>

        <div v-if="filtered.length === 0" class="rounded-xl border border-dashed border-zinc-800 p-10 text-center text-zinc-500">
            Няма отбори по този филтър.
        </div>

        <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Link
                v-for="team in filtered"
                :key="team.slug"
                :href="route('teams.show', team.slug)"
                class="group relative overflow-hidden rounded-xl border border-zinc-800 bg-zinc-900/60 p-5 transition duration-200 hover:border-zinc-600 hover:bg-zinc-900"
            >
                <div class="absolute inset-y-0 left-0 w-1" :style="{ backgroundColor: team.color_hex ?? '#e10600' }" />
                <div class="flex items-center gap-4">
                    <div class="h-14 w-14">
                        <TeamBrand :name="team.name" :slug="team.slug" :color="team.color_hex ?? '#e10600'" variant="emblem" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="truncate text-lg font-bold text-white">{{ team.name_bg ?? team.name }}</span>
                            <span v-if="!team.is_active" class="shrink-0 rounded bg-amber-500/15 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-400">Легенда</span>
                        </div>
                        <div class="text-sm text-zinc-500">
                            <span class="font-semibold text-zinc-300 tabular-nums">{{ team.wins }}</span> победи · {{ team.seasons }} сезона
                        </div>
                    </div>
                    <span class="text-zinc-600 transition group-hover:text-red-500">→</span>
                </div>
            </Link>
        </div>
    </PublicLayout>
</template>
