<script setup>
defineProps({
    value: { type: Number, default: 0 },
});
</script>

<template>
    <span class="flex items-center gap-1">
        <span aria-hidden="true" class="flex items-center gap-1">
            <span v-for="n in 5" :key="n" :class="n <= value ? 'text-red-500' : 'text-zinc-700'">●</span>
            <span class="ml-2">важност</span>
        </span>
        <span class="sr-only">важност {{ value }} от 5</span>
    </span>
</template>
