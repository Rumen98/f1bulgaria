<script setup>
import EmptyState from '@/Components/UI/EmptyState.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    title: { type: String, required: true },
    intro: { type: String, default: '' },
});
</script>

<template>
    <Head :title="title" />

    <PublicLayout>
        <article class="mx-auto max-w-3xl">
            <h1 class="font-display text-2xl font-black sm:text-3xl">{{ title }}</h1>
            <p v-if="intro" class="mt-2 text-zinc-400">{{ intro }}</p>

            <EmptyState class="mt-8">
                Съдържанието на тази страница предстои да бъде публикувано.
            </EmptyState>
        </article>
    </PublicLayout>
</template>
