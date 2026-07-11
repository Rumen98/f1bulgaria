<script setup>
/**
 * Stat плочка (стойност + микро етикет) — каноничният вид за статистики.
 * Стойността идва през слота, за да позволява tabular-nums форматиране,
 * ленти, суфикси и т.н.; акцентен цвят се подава през valueClass.
 */
defineProps({
    label: { type: String, required: true },
    valueClass: { type: String, default: 'text-white' },
});
</script>

<template>
    <div class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-3 text-center">
        <div class="font-display text-2xl font-black tabular-nums" :class="valueClass">
            <slot />
        </div>
        <div class="mt-1 text-xs uppercase tracking-wide text-zinc-500">{{ label }}</div>
    </div>
</template>
