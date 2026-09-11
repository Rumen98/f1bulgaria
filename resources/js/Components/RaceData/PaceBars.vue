<script setup>
import { useChartBox } from '@/composables/useChartBox';
import { bg, lapTime } from '@/utils/chart';
import { computed } from 'vue';

/**
 * Реалното темпо: медианата на чистите обиколки, като изоставане спрямо
 * най-бързия.
 *
 * Медиана, а не средно, и само чисти обиколки — без излизане от пита, без
 * обиколка на влизане и без нищо под safety car. Класацията казва кой е
 * финиширал пръв; това казва кой е бил бърз.
 */
const props = defineProps({
    rows: { type: Array, required: true },
});

// Тук няма SVG, но има същия проблем: фиксираните колони изяждат 208 от 326-те
// пиксела на телефон и от лентата — самата графика — остава педя. На тясно
// редът се пречупва и лентата взима цялата ширина.
const { host, isNarrow } = useChartBox();

const max = computed(() => Math.max(0.001, ...props.rows.map((row) => row.delta)));

const width = (row) => `${Math.max(2, (row.delta / max.value) * 100)}%`;
</script>

<template>
    <ul ref="host" :class="isNarrow ? 'space-y-3' : 'space-y-1'">
        <li
            v-for="row in rows"
            :key="row.number"
            class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm"
        >
            <span
                class="shrink-0 truncate font-mono font-semibold"
                :class="isNarrow ? 'text-xs' : 'w-12 text-[11px]'"
                :style="{ color: row.colour }"
                :title="row.name"
            >
                {{ row.short }}
            </span>

            <div
                class="h-3 overflow-hidden rounded bg-zinc-800/60"
                :class="isNarrow ? 'order-last w-full' : 'grow'"
            >
                <span class="block h-full opacity-80" :style="{ width: width(row), backgroundColor: row.colour }" />
            </div>

            <span
                class="shrink-0 text-right font-mono tabular-nums text-zinc-500"
                :class="isNarrow ? 'ml-auto text-xs' : 'w-20 text-[11px]'"
            >
                {{ lapTime(row.median) }}
            </span>
            <span
                class="shrink-0 text-right font-mono tabular-nums text-zinc-400"
                :class="isNarrow ? 'text-xs' : 'w-14 text-[11px]'"
            >
                {{ row.delta === 0 ? '—' : `+${bg(row.delta, 3)}` }}
            </span>
        </li>
    </ul>
</template>
