<script setup>
defineProps({
    item: { type: Object, required: true },
    featured: { type: Boolean, default: false },
});
</script>

<template>
    <a
        :href="item.url"
        target="_blank"
        rel="noopener"
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
        </div>
    </a>
</template>
