<script setup>
import { computed } from 'vue';
import { TEAM_COLOR_FALLBACK } from '@/utils/racing';

const props = defineProps({
    name: { type: String, default: '' },
    color: { type: String, default: TEAM_COLOR_FALLBACK },
});

const letter = computed(() => (props.name?.charAt(0) ?? '?').toUpperCase());
</script>

<template>
    <svg viewBox="0 0 100 100" class="h-full w-full" role="img" :aria-label="name">
        <circle cx="50" cy="50" r="46" :fill="color" />
        <circle cx="50" cy="50" r="46" fill="none" stroke="rgba(255,255,255,0.25)" stroke-width="2" />
        <text x="50" y="52" text-anchor="middle" dominant-baseline="central" font-size="46" font-weight="800" fill="#ffffff" font-family="system-ui, -apple-system, sans-serif">
            {{ letter }}
        </text>
    </svg>
</template>
