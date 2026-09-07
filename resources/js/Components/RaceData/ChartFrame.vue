<script setup>
/**
 * Общата рамка на всяка графика: заглавие, обяснителен ред и място за легенда.
 *
 * Обяснението не е украса. Графика като „изоставане от лидера" не се чете от
 * човек, който не гледа Формула 1 всяка неделя, а точно той е половината от
 * аудиторията на сайта — едно изречение под заглавието е разликата между
 * „красиво" и „разбираемо".
 */
defineProps({
    title: { type: String, required: true },
    hint: { type: String, default: null },
});
</script>

<template>
    <section class="rounded-2xl border border-zinc-800 bg-zinc-900/60 p-4 sm:p-5">
        <header class="mb-4 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <div class="min-w-0">
                <h2 class="font-display text-lg font-bold text-white">{{ title }}</h2>
                <p v-if="hint" class="mt-0.5 max-w-prose text-sm text-zinc-500">{{ hint }}</p>
            </div>
            <slot name="aside" />
        </header>

        <slot />

        <footer v-if="$slots.legend" class="mt-4 flex flex-wrap gap-x-4 gap-y-2 border-t border-zinc-800 pt-3">
            <slot name="legend" />
        </footer>
    </section>
</template>
