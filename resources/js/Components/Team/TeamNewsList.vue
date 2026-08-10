<script setup>
import { hasRoute } from '@/utils/routes';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    news: { type: Array, default: () => [] },
});

// Картата води към НАШАТА статия (превод, резюме, анализ, коментари) — точно
// както в News/NewsCard. Външният линк остава само като резерва за стари
// записи без slug; източникът е посочен на самата статия.
const items = computed(() =>
    props.news.map((item) => {
        const internal = Boolean(item.slug) && hasRoute('news.show');

        return { ...item, internal, href: internal ? route('news.show', item.slug) : item.url };
    }),
);
</script>

<template>
    <div>
        <h2 class="mb-3 text-lg font-bold text-white">Новини</h2>

        <div v-if="items.length === 0" class="rounded-xl border border-dashed border-zinc-800 p-6 text-center text-sm text-zinc-500">
            Все още няма одобрени новини за този отбор.
        </div>

        <div v-else class="space-y-3">
            <component
                :is="item.internal ? Link : 'a'"
                v-for="(item, i) in items"
                :key="i"
                :href="item.href"
                :target="item.internal ? undefined : '_blank'"
                :rel="item.internal ? undefined : 'noopener noreferrer'"
                class="group block rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 transition duration-200 hover:border-red-600/50 hover:bg-zinc-900"
            >
                <div class="flex items-center gap-2 text-xs text-zinc-500">
                    <span v-if="item.classification" class="rounded bg-zinc-800 px-1.5 py-0.5 text-zinc-300">{{ item.classification }}</span>
                    <span>{{ item.published_at }}</span>
                </div>
                <div class="mt-1 font-semibold text-white transition group-hover:text-red-400">
                    {{ item.title }}
                    <span v-if="!item.internal" class="sr-only">(отваря се в нов раздел)</span>
                </div>
                <p v-if="item.summary" class="mt-1 line-clamp-2 text-sm text-zinc-400">{{ item.summary }}</p>
            </component>
        </div>
    </div>
</template>
