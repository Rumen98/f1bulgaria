<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    drivers: { type: Array, default: () => [] },
});

const form = useForm({ driver_one: '', driver_two: '', title: '' });

const queryOne = ref('');
const queryTwo = ref('');

const highlightedOne = ref(-1);
const highlightedTwo = ref(-1);

const filter = (q) => {
    const term = q.trim().toLowerCase();
    const list = props.drivers;
    return (term ? list.filter((d) => d.name.toLowerCase().includes(term)) : list).slice(0, 8);
};

const suggestionsOne = computed(() => filter(queryOne.value));
const suggestionsTwo = computed(() => filter(queryTwo.value));

const nameOf = (slug) => props.drivers.find((d) => d.slug === slug)?.name ?? '';

const select = (side, driver) => {
    if (side === 'one') {
        form.driver_one = driver.slug;
        queryOne.value = driver.name;
        highlightedOne.value = -1;
    } else {
        form.driver_two = driver.slug;
        queryTwo.value = driver.name;
        highlightedTwo.value = -1;
    }
};

const suggestionsFor = (side) => (side === 'one' ? suggestionsOne.value : suggestionsTwo.value);
const highlightedFor = (side) => (side === 'one' ? highlightedOne.value : highlightedTwo.value);

const onQueryInput = (side, value) => {
    if (side === 'one') {
        queryOne.value = value;
        highlightedOne.value = -1;
    } else {
        queryTwo.value = value;
        highlightedTwo.value = -1;
    }
};

const moveHighlight = (side, delta) => {
    const count = suggestionsFor(side).length;
    if (count === 0) {
        return;
    }
    const target = side === 'one' ? highlightedOne : highlightedTwo;
    target.value = Math.min(Math.max(target.value + delta, 0), count - 1);
};

const pickHighlighted = (side) => {
    const list = suggestionsFor(side);
    const index = highlightedFor(side);
    if (index >= 0 && index < list.length) {
        select(side, list[index]);
    }
};

const clearPicker = (side) => {
    if (side === 'one') {
        queryOne.value = '';
        highlightedOne.value = -1;
    } else {
        queryTwo.value = '';
        highlightedTwo.value = -1;
    }
};

const canSubmit = computed(() => form.driver_one && form.driver_two && form.driver_one !== form.driver_two);

const submit = () => {
    if (canSubmit.value) {
        form.post(route('rivalries.store'));
    }
};
</script>

<template>
    <Head title="Създай дуел" />

    <PublicLayout>
        <Link :href="route('rivalries.index')" class="text-sm text-zinc-500 transition hover:text-zinc-300">← Всички дуели</Link>

        <h1 class="mb-2 mt-3 font-display text-2xl font-black sm:text-3xl">Създай свой дуел</h1>
        <p class="mb-6 text-zinc-400">Избери двама пилоти и създай съперничество, което да споделиш.</p>

        <form @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <div v-for="side in ['one', 'two']" :key="side" class="rounded-2xl border border-zinc-800 bg-zinc-900/60 p-4">
                    <label :for="`driver-input-${side}`" class="mb-2 block text-sm font-medium text-zinc-400">{{ side === 'one' ? 'Пилот 1' : 'Пилот 2' }}</label>
                    <input
                        :id="`driver-input-${side}`"
                        :value="side === 'one' ? queryOne : queryTwo"
                        type="search"
                        placeholder="Търси по име…"
                        aria-autocomplete="list"
                        :aria-expanded="suggestionsFor(side).length > 0"
                        :aria-controls="`driver-list-${side}`"
                        :aria-activedescendant="highlightedFor(side) >= 0 ? `driver-option-${side}-${highlightedFor(side)}` : undefined"
                        class="w-full rounded-lg border border-zinc-800 bg-zinc-950 px-4 py-2.5 text-white placeholder-zinc-500 focus:border-red-600 focus:outline-none focus:ring-1 focus:ring-red-600"
                        @input="onQueryInput(side, $event.target.value)"
                        @keydown.down.prevent="moveHighlight(side, 1)"
                        @keydown.up.prevent="moveHighlight(side, -1)"
                        @keydown.enter.prevent="pickHighlighted(side)"
                        @keydown.esc="clearPicker(side)"
                    />
                    <ul :id="`driver-list-${side}`" role="listbox" class="mt-2 max-h-56 overflow-auto">
                        <li
                            v-for="(d, i) in (side === 'one' ? suggestionsOne : suggestionsTwo)"
                            :id="`driver-option-${side}-${i}`"
                            :key="d.slug"
                            role="option"
                            :aria-selected="(side === 'one' ? form.driver_one : form.driver_two) === d.slug"
                            class="flex cursor-pointer items-center justify-between rounded-lg px-3 py-2.5 text-sm transition hover:bg-zinc-800/60"
                            :class="highlightedFor(side) === i ? 'bg-zinc-800/60 text-white' : (side === 'one' ? form.driver_one : form.driver_two) === d.slug ? 'bg-red-600/20 text-white' : 'text-zinc-300'"
                            @click="select(side, d)"
                        >
                            <span>{{ d.name }}</span>
                            <span class="tabular-nums text-zinc-500">{{ d.wins }} поб.</span>
                        </li>
                    </ul>
                </div>
            </div>

            <div v-if="form.errors.driver_two" class="mt-3 text-sm text-red-400">{{ form.errors.driver_two }}</div>

            <div class="mt-5">
                <label for="rivalry-title" class="mb-1 block text-sm font-medium text-zinc-400">Заглавие (по избор)</label>
                <input
                    id="rivalry-title"
                    v-model="form.title"
                    type="text"
                    :placeholder="form.driver_one && form.driver_two ? `${nameOf(form.driver_one)} срещу ${nameOf(form.driver_two)}` : 'Автоматично от имената'"
                    class="w-full rounded-lg border border-zinc-800 bg-zinc-950 px-4 py-2.5 text-white placeholder-zinc-500 focus:border-red-600 focus:outline-none focus:ring-1 focus:ring-red-600 sm:max-w-md"
                />
            </div>

            <button
                type="submit"
                class="mt-5 w-full rounded-lg bg-red-600 px-5 py-2.5 font-semibold text-white transition enabled:hover:bg-red-500 disabled:cursor-not-allowed disabled:opacity-40 sm:w-auto"
                :disabled="!canSubmit || form.processing"
            >
                Създай дуел →
            </button>
        </form>
    </PublicLayout>
</template>
