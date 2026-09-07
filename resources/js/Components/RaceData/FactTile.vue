<script setup>
/**
 * Плочка с число и КОЙ го е направил.
 *
 * Не е UI/StatTile: там етикетът стои под стойността и няма трети ред, а тук
 * името е половината факт — „338 км/ч" без пилот не казва нищо. Стилът на
 * числото е същият като на StatTile нарочно, за да не изглеждат като две
 * различни системи на една страница.
 */
defineProps({
    label: { type: String, required: true },
    value: { type: String, required: true },
    note: { type: String, default: null },
    colour: { type: String, default: null },
});
</script>

<template>
    <div class="relative overflow-hidden rounded-xl border border-zinc-800 bg-zinc-900/60 p-3">
        <span
            v-if="colour"
            class="absolute inset-y-0 left-0 w-1"
            :style="{ backgroundColor: colour }"
            aria-hidden="true"
        />
        <p class="text-xs uppercase tracking-wide text-zinc-500">{{ label }}</p>
        <p class="mt-1 font-display text-2xl font-black tabular-nums text-white">{{ value }}</p>
        <p v-if="note" class="mt-0.5 truncate text-xs text-zinc-400" :title="note">{{ note }}</p>
    </div>
</template>
