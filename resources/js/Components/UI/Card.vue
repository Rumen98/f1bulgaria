<script setup>
/**
 * Стандартната карта на сайта. Вариантите живеят тук — не подавай
 * padding/повърхност през class, за да не се връща дрейфът
 * (p-3/p-4/p-5 и /40 срещу /60 бяха разпръснати из целия сайт).
 */
defineProps({
    padding: {
        type: String,
        default: 'md',
        validator: (v) => ['sm', 'md', 'lg'].includes(v),
    },
    muted: { type: Boolean, default: false },
});

const PADDING = { sm: 'p-3', md: 'p-4', lg: 'p-5' };
</script>

<template>
    <div
        class="rounded-xl border border-zinc-800"
        :class="[PADDING[padding], muted ? 'bg-zinc-900/40' : 'bg-zinc-900/60']"
    >
        <slot />
    </div>
</template>
