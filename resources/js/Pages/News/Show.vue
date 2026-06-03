<script setup>
import NewsCard from '@/Components/News/NewsCard.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    article: { type: Object, required: true },
    related: { type: Array, default: () => [] },
});

const metaDescription = (props.article.summary ?? props.article.title ?? '').slice(0, 200);
</script>

<template>
    <Head :title="article.title">
        <meta head-key="description" name="description" :content="metaDescription" />
        <meta head-key="og:title" property="og:title" :content="article.title" />
        <meta head-key="og:description" property="og:description" :content="metaDescription" />
        <meta head-key="og:type" property="og:type" content="article" />
        <link head-key="canonical" rel="canonical" :href="article.canonical" />
    </Head>

    <PublicLayout>
        <Link :href="route('news.index')" class="text-sm text-zinc-500 transition hover:text-zinc-300">← Всички новини</Link>

        <div class="mt-3 grid gap-8 lg:grid-cols-3">
            <!-- Статия -->
            <article class="lg:col-span-2">
                <header class="border-b border-zinc-800 pb-6">
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <span v-if="article.classification" class="rounded bg-red-600/90 px-2 py-0.5 font-semibold text-white">
                            {{ article.classification }}
                        </span>
                        <span v-if="article.team" class="inline-flex items-center gap-1 text-zinc-400">
                            <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: article.color ?? '#52525b' }" />
                            {{ article.team }}
                        </span>
                        <span class="ml-auto text-zinc-500">{{ article.published_at }}</span>
                    </div>

                    <h1 class="mt-3 text-3xl font-black leading-tight text-white sm:text-4xl">{{ article.title }}</h1>

                    <div v-if="article.importance" class="mt-3 flex items-center gap-1 text-xs text-zinc-600">
                        <span v-for="n in 5" :key="n" :class="n <= article.importance ? 'text-red-500' : 'text-zinc-700'">●</span>
                        <span class="ml-2">важност</span>
                    </div>
                </header>

                <div v-if="article.summary" class="prose-invert mt-6 text-lg leading-relaxed text-zinc-200">
                    {{ article.summary }}
                </div>
                <p v-else class="mt-6 text-zinc-400">Резюмето на български предстои.</p>

                <!-- Източник / attribution -->
                <div class="mt-8 rounded-2xl border border-zinc-800 bg-zinc-900/60 p-5">
                    <p class="text-sm text-zinc-400">
                        Резюмето и преводът са изготвени от F1 България. Пълната оригинална статия е публикувана от
                        <span v-if="article.source" class="font-semibold text-zinc-200">{{ article.source }}</span>
                        <span v-else>първоизточника</span>.
                    </p>
                    <a
                        v-if="article.external_url"
                        :href="article.external_url"
                        target="_blank"
                        rel="noopener nofollow"
                        class="mt-4 inline-flex items-center gap-2 rounded-lg bg-red-600 px-5 py-2.5 font-semibold text-white transition hover:bg-red-500"
                    >
                        Прочетете оригинала<span v-if="article.source"> на {{ article.source }}</span> →
                    </a>
                </div>
            </article>

            <!-- Свързани новини -->
            <aside v-if="related.length" class="lg:col-span-1">
                <h2 class="mb-3 text-lg font-bold text-white">Свързани новини</h2>
                <div class="grid gap-4">
                    <NewsCard v-for="(item, i) in related" :key="i" :item="item" />
                </div>
            </aside>
        </div>
    </PublicLayout>
</template>
