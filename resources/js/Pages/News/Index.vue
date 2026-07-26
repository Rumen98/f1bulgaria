<script setup>
import NewsCard from '@/Components/News/NewsCard.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { InfiniteScroll, Link } from '@inertiajs/vue3';

defineProps({
    featured: Object,
    items: Object,
    categories: Array,
    activeCat: String,
});
</script>

<template>
    <!-- meta/og таговете идват сървърно от App\Support\Seo (виж app.blade.php). -->

    <PublicLayout>
        <div class="mb-6 flex items-baseline justify-between">
            <h1 class="font-display text-2xl font-black sm:text-3xl">Новини<span class="text-red-600">.</span></h1>
            <span class="text-sm text-zinc-500">Формула 1 на български</span>
        </div>

        <!-- Категории -->
        <div class="mb-6 flex flex-wrap gap-2">
            <Link
                v-for="cat in categories"
                :key="cat.key"
                :href="route('news.index', cat.key === 'all' ? {} : { cat: cat.key })"
                class="rounded-full border px-3 py-2 text-sm font-medium transition duration-200"
                :class="activeCat === cat.key
                    ? 'border-red-600 bg-red-600 text-white'
                    : 'border-zinc-800 bg-zinc-900/60 text-zinc-400 hover:text-white'"
            >
                {{ cat.label }} <span class="tabular-nums opacity-60">{{ cat.count }}</span>
            </Link>
        </div>

        <EmptyState v-if="!featured && items.data.length === 0">
            Все още няма новини в тази категория.
        </EmptyState>

        <!-- Featured -->
        <div v-if="featured" class="mb-6">
            <NewsCard :item="featured" featured />
        </div>

        <!-- Grid с безкраен скрол -->
        <InfiniteScroll data="items" items-element="#news-grid" class="block">
            <div id="news-grid" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <NewsCard v-for="item in items.data" :key="item.slug" :item="item" />
            </div>

            <template #loading>
                <div class="py-8 text-center text-sm text-zinc-500">Зареждане…</div>
            </template>
        </InfiniteScroll>
    </PublicLayout>
</template>
