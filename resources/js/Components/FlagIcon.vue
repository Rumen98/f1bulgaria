<script setup>
/**
 * SVG знаме от flag-icons по ISO alpha-2 код (идва от CountryFlag::iso2).
 * Декоративно е (aria-hidden) — името на пилота стои до него като текст.
 * Непознат/празен код → нищо (без счупени кутийки).
 */
defineProps({
    code: { type: String, default: null },
});
</script>

<template>
    <span
        v-if="code"
        :class="`fi fi-${code}`"
        class="inline-block rounded-[2px] align-[-1px] shadow-sm"
        aria-hidden="true"
    />
</template>
