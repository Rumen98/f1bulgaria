<script setup>
import FlagIcon from '@/Components/FlagIcon.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    season: Number,
    drivers: { type: Array, default: () => [] },
    allTime: { type: Array, default: () => [] },
});

const tab = ref('active');

const legends = computed(() => props.allTime.filter((d) => !d.in_current && d.wins >= 5));

const tabs = computed(() => [
    { key: 'active', label: 'Активни', count: props.drivers.length },
    { key: 'legends', label: 'Легенди', count: legends.value.length },
    { key: 'all', label: 'Всички', count: props.allTime.length },
]);
</script>

<template>
    <!-- meta/og таговете идват сървърно от App\Support\Seo (виж app.blade.php). -->

    <PublicLayout>
        <h1 class="mb-4 font-display text-2xl font-black sm:text-3xl">Пилоти <span class="text-red-600">{{ season }}</span></h1>

        <!-- Табове -->
        <div class="mb-6 flex flex-wrap gap-2">
            <button
                v-for="t in tabs"
                :key="t.key"
                type="button"
                class="rounded-full border px-3 py-2 text-sm font-medium transition duration-200"
                :class="tab === t.key ? 'border-red-600 bg-red-600 text-white' : 'border-zinc-800 bg-zinc-900/60 text-zinc-400 hover:text-white'"
                :aria-pressed="tab === t.key"
                @click="tab = t.key"
            >
                {{ t.label }} <span class="tabular-nums opacity-60">{{ t.count }}</span>
            </button>
        </div>

        <!-- Активни: класиране за сезона -->
        <div v-if="tab === 'active'" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Link
                v-for="d in drivers"
                :key="d.slug"
                :href="route('drivers.show', d.slug)"
                class="group relative flex items-center gap-4 overflow-hidden rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 transition duration-200 hover:border-zinc-600 hover:bg-zinc-900"
            >
                <div class="absolute inset-y-0 left-0 w-1" :style="{ backgroundColor: d.color_hex }" />
                <div class="w-10 text-center text-2xl font-black tabular-nums text-zinc-500 group-hover:text-zinc-400">{{ d.number ?? '—' }}</div>
                <div class="min-w-0 flex-1">
                    <div class="truncate font-bold text-white"><FlagIcon :code="d.flag" class="mr-1" />{{ d.name }}</div>
                    <div class="truncate text-sm text-zinc-500">{{ d.team ?? '—' }}</div>
                </div>
                <div class="text-right">
                    <div class="font-bold tabular-nums text-white">{{ d.points }}</div>
                    <div v-if="d.position" class="text-xs text-zinc-500">P{{ d.position }}</div>
                </div>
            </Link>
        </div>

        <!-- Легенди / Всички: подредени по победи -->
        <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Link
                v-for="d in (tab === 'legends' ? legends : allTime)"
                :key="d.code"
                :href="route('drivers.show', d.slug)"
                class="group relative flex items-center gap-4 overflow-hidden rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 transition duration-200 hover:border-zinc-600 hover:bg-zinc-900"
            >
                <div class="absolute inset-y-0 left-0 w-1" :style="{ backgroundColor: d.color }" />
                <div class="min-w-0 flex-1">
                    <div class="truncate font-bold text-white">{{ d.name }} <span class="text-xs font-normal text-zinc-500">{{ d.code }}</span></div>
                    <div class="text-sm text-zinc-500">{{ d.seasons }} {{ d.seasons === 1 ? 'сезон' : 'сезона' }}</div>
                </div>
                <div class="text-right">
                    <div class="font-bold tabular-nums text-amber-400">{{ d.wins }}</div>
                    <div class="text-xs text-zinc-500">победи</div>
                </div>
            </Link>
        </div>

        <EmptyState v-if="(tab === 'legends' ? legends : tab === 'all' ? allTime : drivers).length === 0">
            Няма пилоти в тази категория все още.
        </EmptyState>
    </PublicLayout>
</template>
