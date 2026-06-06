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
    } else {
        form.driver_two = driver.slug;
        queryTwo.value = driver.name;
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

        <h1 class="mb-2 mt-3 text-2xl font-black sm:text-3xl">Създай свой дуел</h1>
        <p class="mb-6 text-zinc-400">Избери двама пилоти и създай съперничество, което да споделиш.</p>

        <div class="grid gap-4 sm:grid-cols-2">
            <div v-for="side in ['one', 'two']" :key="side" class="rounded-2xl border border-zinc-800 bg-zinc-900/60 p-4">
                <label class="mb-2 block text-sm font-semibold text-zinc-400">{{ side === 'one' ? 'Пилот 1' : 'Пилот 2' }}</label>
                <input
                    :value="side === 'one' ? queryOne : queryTwo"
                    type="search"
                    placeholder="Търси по име…"
                    class="w-full rounded-lg border border-zinc-800 bg-zinc-950 px-4 py-2.5 text-white placeholder-zinc-500 focus:border-red-600 focus:outline-none"
                    @input="side === 'one' ? (queryOne = $event.target.value) : (queryTwo = $event.target.value)"
                />
                <ul class="mt-2 max-h-56 overflow-auto">
                    <li
                        v-for="d in (side === 'one' ? suggestionsOne : suggestionsTwo)"
                        :key="d.slug"
                        class="flex cursor-pointer items-center justify-between rounded-lg px-3 py-2 text-sm transition hover:bg-zinc-800/60"
                        :class="(side === 'one' ? form.driver_one : form.driver_two) === d.slug ? 'bg-red-600/20 text-white' : 'text-zinc-300'"
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
            <label class="mb-1 block text-sm font-semibold text-zinc-400">Заглавие (по избор)</label>
            <input
                v-model="form.title"
                type="text"
                :placeholder="form.driver_one && form.driver_two ? `${nameOf(form.driver_one)} срещу ${nameOf(form.driver_two)}` : 'Автоматично от имената'"
                class="w-full rounded-lg border border-zinc-800 bg-zinc-950 px-4 py-2.5 text-white placeholder-zinc-600 focus:border-red-600 focus:outline-none sm:max-w-md"
            />
        </div>

        <button
            type="button"
            class="mt-5 w-full rounded-xl bg-red-600 py-3 font-bold text-white transition enabled:hover:bg-red-500 disabled:cursor-not-allowed disabled:opacity-40 sm:w-auto sm:px-10"
            :disabled="!canSubmit || form.processing"
            @click="submit"
        >
            Създай дуел →
        </button>
    </PublicLayout>
</template>
