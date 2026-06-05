<script setup>
import NewsGenericImage from '@/Components/News/NewsGenericImage.vue';
import TeamBrand from '@/Components/Team/TeamBrand.vue';
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

        <!-- Банер на отбор: wordmark върху градиент + абстрактни speed lines -->
        <div
            v-else-if="type === 'team_banner'"
            class="relative flex h-full w-full items-center justify-center overflow-hidden"
            :style="{ background: `linear-gradient(135deg, ${data.color ?? '#e10600'}, #0a0a0a 70%)` }"
        >
            <svg viewBox="0 0 320 180" preserveAspectRatio="xMidYMid slice" class="absolute inset-0 h-full w-full opacity-20">
                <g stroke="#ffffff" stroke-width="6" stroke-linecap="round">
                    <line x1="-40" y1="40" x2="120" y2="40" />
                    <line x1="-40" y1="80" x2="180" y2="80" />
                    <line x1="-40" y1="120" x2="90" y2="120" />
                    <line x1="-40" y1="160" x2="150" y2="160" />
                </g>
            </svg>
            <TeamBrand :name="data.name ?? '?'" :slug="data.slug ?? ''" :color="data.color ?? '#e10600'" variant="wordmark" size="lg" class="relative px-6 opacity-95" />
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

        <!-- Premium generic илюстрация по класификация -->
        <NewsGenericImage
            v-else
            :classification="data.classification ?? 'other'"
            :color="data.color ?? '#e10600'"
            :label="data.label ?? 'F1'"
            :title="title"
        />
    </div>
</template>
