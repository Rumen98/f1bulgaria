<script setup>
import { onMounted, ref } from 'vue';

const model = defineModel({
    type: String,
    required: true,
});

const input = ref(null);

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <input
        class="rounded-md border-zinc-700 bg-zinc-900 text-white placeholder-zinc-500 shadow-sm transition focus:border-red-500 focus:ring-red-500"
        v-model="model"
        ref="input"
    />
</template>
