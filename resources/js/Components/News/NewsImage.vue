<script setup>
import TeamMonogram from '@/Components/Team/TeamMonogram.vue';
import { computed } from 'vue';

const props = defineProps({
    image: { type: Object, default: null },
    title: { type: String, default: '' },
});

const svgs = import.meta.glob('../../../svg/circuits/*.svg', { query: '?raw', import: 'default', eager: true });
const trackSvg = (slug) => {
    const key = Object.keys(svgs).find((p) => p.endsWith(`/${slug}.svg`));
    return key ? svgs[key].replace(/<circle[\s\S]*?<\/circle>/i, '') : null;
};

const type = computed(() => props.image?.type ?? 'generic');
const data = computed(() => props.image?.data ?? {});
</script>

<template>
    <div class="relative aspect-video w-full overflow-hidden bg-zinc-900">
        <!-- Снимка на пилот -->
        <template v-if="type === 'driver_photo' && data.photo_url">
            <img
                :src="data.photo_url"
                :alt="data.name ?? title"
                loading="lazy"
                referrerpolicy="no-referrer"
                class="h-full w-full object-cover object-top"
            />
            <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent" />
            <span v-if="data.name" class="absolute bottom-2 left-3 text-sm font-bold text-white drop-shadow">{{ data.name }}</span>
        </template>

        <!-- Банер на отбор -->
        <div
            v-else-if="type === 'team_banner'"
            class="flex h-full w-full items-center justify-center"
            :style="{ background: `linear-gradient(135deg, ${data.color ?? '#e10600'}, #0a0a0a 70%)` }"
        >
            <div class="h-16 w-16 opacity-90 sm:h-20 sm:w-20">
                <TeamMonogram :name="data.name ?? '?'" :color="data.color ?? '#e10600'" />
            </div>
        </div>

        <!-- Очертание на писта -->
        <div v-else-if="type === 'circuit_outline'" class="flex h-full w-full items-center justify-center bg-gradient-to-br from-zinc-900 via-zinc-950 to-black p-4">
            <div
                v-if="trackSvg(data.slug)"
                class="h-full text-zinc-300 [&_svg]:mx-auto [&_svg]:h-full [&_svg]:max-h-full [&_svg]:w-auto"
                v-html="trackSvg(data.slug)"
            />
            <span v-else class="text-3xl">🏁</span>
        </div>

        <!-- Generic банер по категория -->
        <div
            v-else
            class="flex h-full w-full items-center justify-center"
            :style="{ background: `linear-gradient(135deg, ${data.color ?? '#e10600'}, #0a0a0a 70%)` }"
        >
            <span class="text-xs font-bold uppercase tracking-[0.25em] text-white/90">{{ data.label ?? 'F1' }}</span>
        </div>
    </div>
</template>
