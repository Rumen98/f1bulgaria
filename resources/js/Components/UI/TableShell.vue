<script setup>
/**
 * Обвивка за таблици — каноничната рамка (радиус/бордер) на едно място.
 * scroll=true за широки таблици, които трябва да скролват хоризонтално.
 */
defineProps({
    scroll: { type: Boolean, default: false },
});
</script>

<template>
    <div class="rounded-xl border border-zinc-800" :class="scroll ? 'overflow-x-auto' : 'overflow-hidden'">
        <slot />
    </div>
</template>
