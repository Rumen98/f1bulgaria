<script setup>
import { computed } from 'vue';

const props = defineProps({
    track: { type: Object, default: null },
    trackCount: { type: Number, default: 0 },
    outline: { type: String, default: '' },
    loading: { type: Boolean, default: false },
});

defineEmits(['drive']);

const trackLength = computed(() => Number.isFinite(props.track?.length)
    ? (props.track.length / 1000).toFixed(3)
    : null);
const trackElevation = computed(() => Number.isFinite(props.track?.elevation) && props.track.elevation > 3
    ? Math.round(props.track.elevation)
    : null);
</script>

<template>
    <section class="lobby-hero relative isolate mb-10 overflow-hidden border border-white/10 bg-[#101114]" aria-labelledby="game-lobby-title">
        <div class="hero-accent absolute inset-y-0 left-0 w-1 bg-[#e10600]" aria-hidden="true"></div>
        <div class="grid" :class="track ? 'md:grid-cols-[1.05fr_1fr]' : ''">
            <div class="relative z-10 flex flex-col items-start px-6 py-8 sm:px-8 sm:py-10 lg:px-10">
                <div class="flex items-center gap-3 text-[10px] font-bold uppercase tracking-[0.23em]">
                    <span class="flex items-center gap-2 text-zinc-100">
                        Падок<span class="h-1.5 w-1.5 rounded-full bg-[#e10600]" aria-hidden="true"></span>
                    </span>
                    <span class="text-zinc-600" aria-hidden="true">/</span>
                    <span class="text-zinc-400">Симулатор</span>
                </div>

                <h1 id="game-lobby-title" class="hero-title mt-7 font-display font-black text-zinc-100">
                    Твоето място е<br />
                    на стартовата<br />
                    <span class="text-[#ff4943]">решетка.</span>
                </h1>
                <p class="mt-5 max-w-sm text-sm leading-relaxed text-zinc-400">
                    Усети всеки завой. Намери идеалната линия.
                    Подобри времето си на реални писти от света на Формула 1.
                </p>

                <div class="mt-7 flex w-full flex-wrap items-center gap-x-5 gap-y-3">
                    <button
                        v-if="track"
                        type="button"
                        class="hero-drive group inline-flex min-h-12 items-center justify-center gap-5 rounded-md bg-[#e10600] px-6 py-3 text-xs font-black uppercase tracking-widest text-white transition-colors hover:bg-[#fa241d] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white disabled:cursor-wait disabled:opacity-60"
                        :disabled="loading"
                        :aria-label="loading ? 'Зареждане на пистата' : `Карай сега на ${track.name}`"
                        @click="$emit('drive')"
                    >
                        {{ loading ? 'Зареждане…' : 'Карай сега' }}
                        <svg class="hero-drive-arrow h-4 w-4" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                            <path d="M3 10h13m-5-5 5 5-5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                    <span v-if="trackCount" class="text-xs text-zinc-500">
                        <span class="font-display font-bold tabular-nums text-zinc-200">{{ trackCount }}</span>
                        {{ trackCount === 1 ? 'писта' : 'писти' }} за следващата ти обиколка.
                    </span>
                </div>

                <div class="mt-7 hidden flex-wrap items-center gap-x-4 gap-y-2 border-t border-white/[0.07] pt-4 text-[10px] text-zinc-500 sm:flex">
                    <span class="inline-flex items-center gap-1.5"><kbd>↑</kbd><kbd>↓</kbd> газ / спирачка</span>
                    <span class="inline-flex items-center gap-1.5"><kbd>←</kbd><kbd>→</kbd> завиване</span>
                    <span class="inline-flex items-center gap-1.5"><kbd>C</kbd> камера</span>
                </div>
            </div>

            <div v-if="track" class="hero-circuit relative flex min-w-0 flex-col border-t border-white/[0.07] md:border-l md:border-t-0">
                <div class="relative z-10 flex items-center justify-between gap-3 px-6 pt-6 sm:px-8 md:pt-9">
                    <span class="text-[9px] font-semibold uppercase tracking-[0.2em] text-zinc-500">Твоята следваща писта</span>
                    <svg class="h-4 w-4 text-[#ff4943]" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                        <path d="M4 16 16 4M4 4h12v12" stroke="currentColor" stroke-width="1.5" />
                    </svg>
                </div>

                <div class="relative mx-6 flex min-h-[190px] flex-1 items-center justify-center py-2 sm:mx-8 md:min-h-[260px]" aria-hidden="true">
                    <div class="circuit-crosshair circuit-crosshair-top"></div>
                    <div class="circuit-crosshair circuit-crosshair-bottom"></div>
                    <svg v-if="outline" class="circuit-outline relative z-10 h-[190px] w-full md:h-[265px]" viewBox="-10 -10 120 120" fill="none">
                        <path :d="outline" transform="translate(0 2)" stroke="#020203" stroke-width="5" stroke-linecap="round" stroke-linejoin="round" />
                        <path :d="outline" stroke="rgba(255,255,255,.07)" stroke-width="5" stroke-linecap="round" stroke-linejoin="round" />
                        <path :d="outline" stroke="#e5e7eb" stroke-width="1.15" stroke-linecap="round" stroke-linejoin="round" />
                        <path :d="outline" stroke="#ff4943" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" pathLength="100" stroke-dasharray="12 88" />
                    </svg>
                </div>

                <div class="relative z-10 flex flex-wrap items-end justify-between gap-x-4 gap-y-3 border-t border-white/[0.07] px-6 py-5 sm:px-8 md:py-6">
                    <div class="min-w-0 flex-1">
                        <p class="text-[9px] font-semibold uppercase tracking-[0.2em] text-zinc-500">{{ track.location }}</p>
                        <h2 class="mt-1.5 break-words font-display text-lg font-bold leading-tight text-zinc-100">{{ track.name }}</h2>
                    </div>
                    <div class="flex shrink-0 items-end gap-4">
                        <div v-if="trackLength" class="text-right">
                            <div class="font-display text-xl font-black tabular-nums text-zinc-100">{{ trackLength }}</div>
                            <div class="text-[9px] uppercase tracking-widest text-zinc-500">километра</div>
                        </div>
                        <div v-if="trackElevation" class="hidden text-right lg:block">
                            <div class="font-display text-xl font-black tabular-nums text-zinc-300">{{ trackElevation }}<span class="ml-1 text-xs">м</span></div>
                            <div class="text-[9px] uppercase tracking-widest text-zinc-500">денивелация</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>

<style scoped>
.lobby-hero {
    border-radius: 1rem;
    background-image: linear-gradient(120deg, rgba(255, 255, 255, 0.018), transparent 55%);
}

.hero-title {
    font-size: clamp(2rem, 3.8vw, 3.25rem);
    line-height: 1.08;
    letter-spacing: -0.045em;
}

.hero-circuit {
    background-color: #0c0d10;
    background-image:
        linear-gradient(rgba(255, 255, 255, 0.025) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255, 255, 255, 0.025) 1px, transparent 1px);
    background-size: 28px 28px;
}

.hero-circuit::before {
    position: absolute;
    inset: 0;
    background: linear-gradient(180deg, #0c0d10, transparent 25%, transparent 70%, #0c0d10);
    content: '';
    pointer-events: none;
}

.circuit-crosshair {
    position: absolute;
    width: 12px;
    height: 12px;
    color: rgba(255, 255, 255, 0.2);
}

.circuit-crosshair::before,
.circuit-crosshair::after {
    position: absolute;
    background: currentColor;
    content: '';
}

.circuit-crosshair::before {
    top: 5px;
    width: 12px;
    height: 1px;
}

.circuit-crosshair::after {
    left: 5px;
    width: 1px;
    height: 12px;
}

.circuit-crosshair-top {
    top: 25px;
    left: 0;
}

.circuit-crosshair-bottom {
    right: 0;
    bottom: 25px;
}

kbd {
    display: inline-grid;
    min-width: 1.3rem;
    height: 1.3rem;
    place-items: center;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 3px;
    background: rgba(255, 255, 255, 0.03);
    font-family: inherit;
    color: #a1a1aa;
}

.hero-drive-arrow {
    transition: transform 180ms ease;
}

.hero-drive:hover .hero-drive-arrow {
    transform: translateX(3px);
}

@media (prefers-reduced-motion: reduce) {
    .hero-drive,
    .hero-drive-arrow {
        transition: none;
    }

    .hero-drive:hover .hero-drive-arrow {
        transform: none;
    }
}
</style>
