<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    drivers: { type: Array, default: () => [] },
    presets: { type: Array, default: () => [] },
});

const pick = { a: ref(null), b: ref(null) };
const queryA = ref('');
const queryB = ref('');

const filter = (q) => {
    const term = q.trim().toLowerCase();
    if (!term) {
        return props.drivers.slice(0, 8);
    }
    return props.drivers.filter((d) => d.name.toLowerCase().includes(term)).slice(0, 8);
};

const suggestionsA = computed(() => filter(queryA.value));
const suggestionsB = computed(() => filter(queryB.value));

const select = (side, driver) => {
    pick[side].value = driver;
    if (side === 'a') {
        queryA.value = driver.name;
    } else {
        queryB.value = driver.name;
    }
};

const canCompare = computed(() => pick.a.value && pick.b.value && pick.a.value.slug !== pick.b.value.slug);

const submit = () => {
    if (canCompare.value) {
        router.visit(route('compare.show', [pick.a.value.slug, pick.b.value.slug]));
    }
};

const goPreset = (p) => router.visit(route('compare.show', [p.a, p.b]));
</script>

<template>
    <Head title="Сравнение на пилоти" />

    <PublicLayout>
        <h1 class="mb-2 text-2xl font-black sm:text-3xl">Сравни пилоти</h1>
        <p class="mb-6 text-zinc-400">Избери двама пилоти и виж статистиката им един до друг.</p>

        <div class="grid gap-4 sm:grid-cols-2">
            <div v-for="side in ['a', 'b']" :key="side" class="rounded-2xl border border-zinc-800 bg-zinc-900/60 p-4">
                <label class="mb-2 block text-sm font-semibold text-zinc-400">{{ side === 'a' ? 'Пилот 1' : 'Пилот 2' }}</label>
                <input
                    :value="side === 'a' ? queryA : queryB"
                    type="search"
                    placeholder="Търси по име…"
                    class="w-full rounded-lg border border-zinc-800 bg-zinc-950 px-4 py-2.5 text-white placeholder-zinc-500 focus:border-red-600 focus:outline-none"
                    @input="side === 'a' ? (queryA = $event.target.value) : (queryB = $event.target.value)"
                />
                <ul class="mt-2 max-h-60 overflow-auto">
                    <li
                        v-for="d in (side === 'a' ? suggestionsA : suggestionsB)"
                        :key="d.slug"
                        class="flex cursor-pointer items-center justify-between rounded-lg px-3 py-2 text-sm transition hover:bg-zinc-800/60"
                        :class="(side === 'a' ? pick.a.value?.slug : pick.b.value?.slug) === d.slug ? 'bg-red-600/20 text-white' : 'text-zinc-300'"
                        @click="select(side, d)"
                    >
                        <span>{{ d.name }}</span>
                        <span class="tabular-nums text-zinc-500">{{ d.wins }} поб.</span>
                    </li>
                </ul>
            </div>
        </div>

        <button
            type="button"
            class="mt-5 w-full rounded-xl bg-red-600 py-3 font-bold text-white transition enabled:hover:bg-red-500 disabled:cursor-not-allowed disabled:opacity-40 sm:w-auto sm:px-10"
            :disabled="!canCompare"
            @click="submit"
        >
            Сравни →
        </button>

        <div v-if="presets.length" class="mt-10">
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-500">Популярни сравнения</h2>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="p in presets"
                    :key="p.label"
                    type="button"
                    class="rounded-full border border-zinc-700 bg-zinc-900/60 px-4 py-2 text-sm font-medium text-zinc-200 transition hover:border-red-600 hover:text-white"
                    @click="goPreset(p)"
                >
                    {{ p.label }}
                </button>
            </div>
        </div>
    </PublicLayout>
</template>
