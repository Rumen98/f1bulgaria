<script setup>
import EmptyState from '@/Components/UI/EmptyState.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    title: { type: String, required: true },
    intro: { type: String, default: '' },
    updatedAt: { type: String, default: '' },
    /** Секции: [{ heading, paragraphs: [], items?: [] }] */
    sections: { type: Array, default: () => [] },
});
</script>

<template>
    <Head :title="title" />

    <PublicLayout>
        <article class="mx-auto max-w-3xl">
            <h1 class="font-display text-2xl font-black sm:text-3xl">{{ title }}</h1>
            <p v-if="intro" class="mt-2 text-zinc-400">{{ intro }}</p>
            <p v-if="updatedAt" class="mt-1 text-xs uppercase tracking-wide text-zinc-500">
                Последна редакция: {{ updatedAt }}
            </p>

            <template v-if="sections.length">
                <section v-for="section in sections" :key="section.heading" class="mt-8">
                    <h2 class="font-display text-lg font-bold text-white">{{ section.heading }}</h2>
                    <p
                        v-for="(paragraph, i) in section.paragraphs"
                        :key="i"
                        class="mt-3 leading-relaxed text-zinc-300"
                    >
                        {{ paragraph }}
                    </p>
                    <ul v-if="section.items" class="mt-3 list-disc space-y-1.5 pl-5 text-zinc-300">
                        <li v-for="(item, i) in section.items" :key="i">{{ item }}</li>
                    </ul>
                </section>
            </template>

            <EmptyState v-else class="mt-8">
                Съдържанието на тази страница предстои да бъде публикувано.
            </EmptyState>
        </article>
    </PublicLayout>
</template>
