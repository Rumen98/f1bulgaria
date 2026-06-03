<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    item: { type: Object, required: true },
    featured: { type: Boolean, default: false },
});

// Картата води към собствената ни article страница; към източника се отива
// само през малката икона долу.
const hasInternal = computed(() => {
    if (!props.item.slug) {
        return false;
    }
    try {
        return route().has('news.show');
    } catch (e) {
        return false;
    }
});

const href = computed(() => (hasInternal.value ? route('news.show', props.item.slug) : props.item.url));
</script>

<template>
    <component
        :is="hasInternal ? Link : 'a'"
        :href="href"
        :target="hasInternal ? undefined : '_blank'"
        :rel="hasInternal ? undefined : 'noopener'"
        class="group flex flex-col overflow-hidden rounded-xl border border-zinc-800 bg-zinc-900/60 transition duration-200 hover:border-red-600/50 hover:bg-zinc-900"
        :class="featured ? 'p-6' : 'p-4'"
    >
        <div class="flex items-center gap-2 text-xs">
            <span v-if="item.classification" class="rounded bg-zinc-800 px-1.5 py-0.5 text-zinc-300">{{ item.classification }}</span>
            <span v-if="item.team" class="inline-flex items-center gap-1 text-zinc-400">
                <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: item.color ?? '#52525b' }" />
                {{ item.team }}
            </span>
            <span class="ml-auto text-zinc-600">{{ item.published_at }}</span>
        </div>

        <h3
            class="mt-2 font-bold text-white transition group-hover:text-red-400"
            :class="featured ? 'text-2xl sm:text-3xl' : 'text-base'"
        >
            {{ item.title }}
        </h3>

        <p v-if="item.summary" class="mt-2 text-sm text-zinc-400" :class="featured ? '' : 'line-clamp-3'">
            {{ item.summary }}
        </p>

        <div class="mt-3 flex items-center gap-1 text-xs text-zinc-600">
            <span v-for="n in 5" :key="n" :class="n <= (item.importance ?? 0) ? 'text-red-500' : 'text-zinc-700'">●</span>
            <span class="ml-2">важност</span>
            <a
                v-if="item.url"
                :href="item.url"
                target="_blank"
                rel="noopener nofollow"
                class="ml-auto inline-flex items-center gap-1 text-zinc-500 transition hover:text-zinc-300"
                title="Към оригиналния източник"
                aria-label="Към оригиналния източник"
                @click.stop
            >
                източник ↗
            </a>
        </div>
    </component>
</template>
