<script setup>
import NewsCard from '@/Components/News/NewsCard.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    featured: Object,
    items: Array,
    categories: Array,
    activeCat: String,
});
</script>

<template>
    <Head title="Новини" />

    <PublicLayout>
        <div class="mb-6 flex items-baseline justify-between">
            <h1 class="text-2xl font-black sm:text-3xl">Новини<span class="text-red-600">.</span></h1>
            <span class="text-sm text-zinc-500">F1 на български</span>
        </div>

        <!-- Категории -->
        <div class="mb-6 flex flex-wrap gap-2">
            <Link
                v-for="cat in categories"
                :key="cat.key"
                :href="route('news.index', cat.key === 'all' ? {} : { cat: cat.key })"
                class="rounded-full border px-3 py-1.5 text-sm font-medium transition duration-200"
                :class="activeCat === cat.key
                    ? 'border-red-600 bg-red-600 text-white'
                    : 'border-zinc-800 bg-zinc-900/60 text-zinc-400 hover:text-white'"
            >
                {{ cat.label }} <span class="tabular-nums opacity-60">{{ cat.count }}</span>
            </Link>
        </div>

        <div v-if="!featured && items.length === 0" class="rounded-xl border border-dashed border-zinc-800 p-12 text-center text-zinc-500">
            Все още няма одобрени новини в тази категория.
        </div>

        <!-- Featured -->
        <div v-if="featured" class="mb-6">
            <NewsCard :item="featured" featured />
        </div>

        <!-- Grid -->
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <NewsCard v-for="(item, i) in items" :key="i" :item="item" />
        </div>
    </PublicLayout>
</template>
