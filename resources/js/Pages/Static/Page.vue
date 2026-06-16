<script setup>
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
        <article class="mx-auto max-w-2xl">
            <h1 class="text-2xl font-black sm:text-3xl">{{ title }}</h1>
            <p v-if="intro" class="mt-2 text-zinc-400">{{ intro }}</p>

            <div class="mt-8 rounded-2xl border border-dashed border-zinc-800 bg-zinc-900/40 p-8 text-center text-zinc-500">
                Съдържанието на тази страница предстои да бъде публикувано.
            </div>
        </article>
    </PublicLayout>
</template>
