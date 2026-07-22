<script setup>
import FlagIcon from '@/Components/FlagIcon.vue';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { TEAM_COLOR_FALLBACK } from '@/utils/racing';
import { hasRoute } from '@/utils/routes';

const props = defineProps({
    driver: { type: Object, required: true },
    color: { type: String, default: TEAM_COLOR_FALLBACK },
});

// Линк към driver page само ако route-ът съществува (Task 3).
const href = computed(() => (hasRoute('drivers.show') ? route('drivers.show', props.driver.slug) : null));
</script>

<template>
    <component
        :is="href ? Link : 'div'"
        :href="href || undefined"
        class="group flex items-center gap-4 rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 transition duration-200"
        :class="href ? 'hover:border-zinc-600 hover:bg-zinc-900' : ''"
    >
        <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full font-display text-xl font-black text-white" :style="{ backgroundColor: color }">
            {{ driver.code ?? driver.name.charAt(0) }}
        </div>
        <div class="min-w-0 flex-1">
            <div class="truncate font-semibold text-white">
                <FlagIcon :code="driver.flag" class="mr-1" />{{ driver.name }}
            </div>
            <div class="text-sm text-zinc-500">
                <span v-if="driver.number">#{{ driver.number }}</span>
            </div>
        </div>
        <div class="text-right">
            <div class="font-bold tabular-nums text-white">{{ driver.points }} т.</div>
            <div v-if="driver.position" class="text-xs text-zinc-500">P{{ driver.position }}</div>
        </div>
    </component>
</template>
