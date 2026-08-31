<script setup>
import { badgeArt } from '@/utils/badgeArt';
import { computed } from 'vue';

const props = defineProps({
    // { slug, name, description, earned, awarded_at }
    badge: { type: Object, required: true },
});

const art = computed(() => badgeArt(props.badge.slug));
</script>

<template>
    <li
        class="flex items-start gap-3.5 rounded-xl border p-4 transition duration-200"
        :class="badge.earned
            ? [art.border, art.tint]
            : 'border-dashed border-zinc-800 bg-zinc-950/40'"
    >
        <!-- Иконата: цветен кръг за спечелена, посивен за заключена. -->
        <div
            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-gradient-to-br text-2xl shadow-lg"
            :class="badge.earned ? [art.circle, art.glow] : 'from-zinc-800 to-zinc-900 opacity-70 grayscale'"
            aria-hidden="true"
        >
            {{ art.emoji }}
        </div>

        <div class="min-w-0">
            <div class="flex flex-wrap items-baseline gap-x-2">
                <h3 class="font-display text-sm font-black" :class="badge.earned ? 'text-white' : 'text-zinc-400'">
                    {{ badge.name }}
                </h3>
                <span v-if="!badge.earned" class="text-xs text-zinc-600" aria-hidden="true">🔒</span>
            </div>

            <!-- Условието стои ВИДИМО, не в title tooltip: на телефон hover
                 няма, а смисълът на заключената значка е да казва как се гони. -->
            <p class="mt-1 text-sm leading-snug" :class="badge.earned ? 'text-zinc-300' : 'text-zinc-500'">
                {{ badge.description }}
            </p>

            <p
                v-if="badge.earned && badge.awarded_at"
                class="mt-1.5 text-[11px] font-semibold uppercase tracking-wide text-zinc-500"
            >
                Спечелена · {{ badge.awarded_at }}
            </p>
            <p
                v-else-if="!badge.earned"
                class="mt-1.5 text-[11px] font-semibold uppercase tracking-wide text-zinc-600"
            >
                Още не е спечелена
            </p>

            <span class="sr-only">{{ badge.earned ? 'Спечелена значка.' : 'Заключена значка.' }}</span>
        </div>
    </li>
</template>
