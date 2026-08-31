<script setup>
import ImportanceDots from '@/Components/News/ImportanceDots.vue';
import NewsImage from '@/Components/News/NewsImage.vue';
import { NEUTRAL_DOT_COLOR } from '@/utils/racing';
import { hasRoute } from '@/utils/routes';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

// Водещата новина има собствен компонент — FeaturedNewsCard.
const props = defineProps({
    item: { type: Object, required: true },
});

// Картата води само към собствената ни article страница. Външният линк към
// източника нарочно го няма — изнасяше трафика още от списъка, преди човек
// изобщо да е стигнал до нашия текст.
const hasInternal = computed(() => Boolean(props.item.slug) && hasRoute('news.show'));

const href = computed(() => (hasInternal.value ? route('news.show', props.item.slug) : props.item.url));
</script>

<template>
    <article
        class="group relative flex flex-col overflow-hidden rounded-xl border border-zinc-800 bg-zinc-900/60 transition duration-200 hover:border-red-600/50 hover:bg-zinc-900"
    >
        <NewsImage v-if="item.image" :image="item.image" :title="item.title" />

        <div class="flex flex-1 flex-col p-4">
            <div class="flex items-center gap-2 text-xs">
                <span v-if="item.classification" class="rounded bg-zinc-800 px-1.5 py-0.5 text-zinc-300">{{ item.classification }}</span>
                <span v-if="item.team" class="inline-flex items-center gap-1 text-zinc-400">
                    <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: item.color ?? NEUTRAL_DOT_COLOR }" />
                    {{ item.team }}
                </span>
                <span class="ml-auto text-zinc-500">{{ item.published_at }}</span>
            </div>

            <h3 class="mt-2 text-base font-bold text-white transition group-hover:text-red-400">
                <!-- Stretched link: покрива цялата карта без невалидни вложени котви -->
                <component
                    :is="hasInternal ? Link : 'a'"
                    :href="href"
                    :target="hasInternal ? undefined : '_blank'"
                    :rel="hasInternal ? undefined : 'noopener'"
                    :prefetch="hasInternal ? 'hover' : undefined"
                    class="after:absolute after:inset-0"
                >
                    {{ item.title }}
                </component>
            </h3>

            <p v-if="item.summary" class="mt-2 line-clamp-3 text-sm text-zinc-400">
                {{ item.summary }}
            </p>

            <!-- Без линк към източника: картата има една задача — да отведе
                 до нашата статия. Атрибуцията живее в дъното на самата статия. -->
            <div class="mt-3 flex items-center gap-1 text-xs text-zinc-600">
                <ImportanceDots :value="item.importance ?? 0" />
            </div>
        </div>
    </article>
</template>
