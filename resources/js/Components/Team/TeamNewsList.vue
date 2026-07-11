<script setup>
defineProps({
    news: { type: Array, default: () => [] },
});
</script>

<template>
    <div>
        <h2 class="mb-3 text-lg font-bold text-white">Новини</h2>

        <div v-if="news.length === 0" class="rounded-xl border border-dashed border-zinc-800 p-6 text-center text-sm text-zinc-500">
            Все още няма одобрени новини за този отбор.
        </div>

        <div v-else class="space-y-3">
            <a
                v-for="(item, i) in news"
                :key="i"
                :href="item.url"
                target="_blank"
                rel="noopener noreferrer"
                class="group block rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 transition duration-200 hover:border-red-600/50 hover:bg-zinc-900"
            >
                <div class="flex items-center gap-2 text-xs text-zinc-500">
                    <span v-if="item.classification" class="rounded bg-zinc-800 px-1.5 py-0.5 text-zinc-300">{{ item.classification }}</span>
                    <span>{{ item.published_at }}</span>
                </div>
                <div class="mt-1 font-semibold text-white transition group-hover:text-red-400">{{ item.title }} <span class="sr-only">(отваря се в нов раздел)</span></div>
                <p v-if="item.summary" class="mt-1 line-clamp-2 text-sm text-zinc-400">{{ item.summary }}</p>
            </a>
        </div>
    </div>
</template>
