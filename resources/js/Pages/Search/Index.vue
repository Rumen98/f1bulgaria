<script setup>
import EmptyState from '@/Components/UI/EmptyState.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    term: { type: String, default: '' },
    groups: { type: Array, default: () => [] },
    total: { type: Number, default: 0 },
    minLength: { type: Number, default: 2 },
});

const query = ref(props.term);
const input = ref(null);

// Поддържа полето в синхрон при навигация назад/напред.
watch(() => props.term, (value) => (query.value = value));

const submit = () => {
    router.get(route('search'), { q: query.value }, { preserveState: true, replace: true });
};
</script>

<template>
    <PublicLayout>
        <h1 class="mb-4 font-display text-2xl font-black sm:text-3xl">Търсене<span class="text-red-600">.</span></h1>

        <form class="mb-8" role="search" @submit.prevent="submit">
            <label for="search-input" class="sr-only">Търси в Падок</label>
            <div class="flex gap-2">
                <input
                    id="search-input"
                    ref="input"
                    v-model="query"
                    type="search"
                    autofocus
                    placeholder="Пилот, отбор, състезание, писта, новина или термин…"
                    class="w-full rounded-lg border-zinc-700 bg-zinc-900 px-4 py-2.5 text-white placeholder-zinc-500 focus:border-red-600 focus:ring-red-600"
                >
                <button
                    type="submit"
                    class="shrink-0 rounded-lg bg-red-600 px-5 py-2.5 font-semibold text-white transition hover:bg-red-500"
                >
                    Търси
                </button>
            </div>
        </form>

        <EmptyState v-if="term.length && term.length < minLength">
            Въведи поне {{ minLength }} знака.
        </EmptyState>

        <EmptyState v-else-if="term.length >= minLength && total === 0">
            Няма резултати за „{{ term }}“. Опитай с име на пилот, отбор или състезание.
        </EmptyState>

        <p v-else-if="!term.length" class="text-sm text-zinc-500">
            Търси измежду пилоти, отбори, състезания, писти, новини и термини от речника.
        </p>

        <div v-if="total" class="space-y-8">
            <p class="text-sm text-zinc-500">
                {{ total }} {{ total === 1 ? 'резултат' : 'резултата' }} за „{{ term }}“
            </p>

            <section v-for="group in groups" :key="group.key">
                <h2 class="mb-3 font-display text-sm font-black uppercase tracking-[0.2em] text-zinc-500">
                    {{ group.label }}
                </h2>
                <ul class="divide-y divide-zinc-800/60 overflow-hidden rounded-xl border border-zinc-800 bg-zinc-900/40">
                    <li v-for="(item, i) in group.items" :key="i">
                        <Link
                            :href="item.url"
                            class="flex items-baseline gap-3 px-4 py-3 transition hover:bg-zinc-800/40"
                        >
                            <span class="min-w-0 flex-1">
                                <span class="block font-semibold text-white">{{ item.title }}</span>
                                <span v-if="item.subtitle" class="mt-0.5 block truncate text-sm text-zinc-500">
                                    {{ item.subtitle }}
                                </span>
                            </span>
                            <span v-if="item.meta" class="shrink-0 text-xs tabular-nums text-zinc-600">{{ item.meta }}</span>
                        </Link>
                    </li>
                </ul>
            </section>
        </div>
    </PublicLayout>
</template>
