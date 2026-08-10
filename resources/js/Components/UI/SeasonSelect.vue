<script setup>
/**
 * Селектор за сезон — един вид на всички публични страници.
 *
 * `pr-9` е същественото: @tailwindcss/forms рисува собствена стрелка като
 * background-image (`right .5rem center`) и компенсира с `padding-right: 2.5rem`.
 * Кратко `px-3` презаписва точно това и стрелката ляга върху текста — на iOS
 * Safari изглежда като кръг зад цифрите на годината.
 *
 * `@change` (и останалите слушатели) падат през attrs към самия <select>.
 */
defineProps({
    seasons: { type: Array, required: true },
    selected: { type: [Number, String], default: null },
    label: { type: String, default: 'Сезон' },
    /** „Сезон 2026" на страницата на отбора, само „2026" в класирането. */
    prefix: { type: String, default: '' },
});
</script>

<template>
    <select
        :value="selected"
        :aria-label="label"
        class="rounded-lg border border-zinc-800 bg-zinc-900 py-2 pl-3 pr-9 text-sm text-white focus:border-red-600 focus:outline-none focus:ring-1 focus:ring-red-600"
    >
        <option v-for="y in seasons" :key="y" :value="y">{{ prefix }}{{ y }}</option>
    </select>
</template>
