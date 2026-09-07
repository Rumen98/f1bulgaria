<script setup>
import { useChartBox } from '@/composables/useChartBox';
import { computed } from 'vue';

/**
 * Спечелени и загубени позиции спрямо стартовата решетка.
 *
 * Двустранна лента от обща средна линия, а не slope chart с имена от двете
 * страни: при двадесет пилота slope chart-ът става нечетим на телефон, а
 * половината трафик на сайта е точно оттам. Тук един ред е един пилот и
 * посоката на лентата казва всичко още преди да си прочел числото.
 */
const props = defineProps({
    rows: { type: Array, required: true },
});

// Половината трафик е от телефон, а там фиксираните колони оставяха на самата
// лента около трета от реда. На тясно тя слиза на свой ред и взима всичко.
const { host, isNarrow } = useChartBox();

const widest = computed(() =>
    Math.max(1, ...props.rows.map((row) => Math.abs(row.from - row.to))),
);

const width = (row) => `${(Math.abs(row.from - row.to) / widest.value) * 50}%`;

const gained = (row) => row.from - row.to;
</script>

<template>
    <ul ref="host" :class="isNarrow ? 'space-y-3' : 'space-y-1'">
        <li v-for="row in rows" :key="row.number" class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
            <span
                class="shrink-0 truncate font-mono font-semibold"
                :class="isNarrow ? 'text-xs' : 'w-12 text-[11px]'"
                :style="{ color: row.colour }"
                :title="row.name"
            >
                {{ row.short }}
            </span>

            <div class="relative h-4" :class="isNarrow ? 'order-last w-full' : 'grow'">
                <!-- Средната линия е нулата: вляво е загуба, вдясно печалба. -->
                <span class="absolute inset-y-0 left-1/2 w-px bg-zinc-700" aria-hidden="true" />

                <span
                    v-if="gained(row) > 0"
                    class="absolute inset-y-0.5 left-1/2 rounded-r bg-emerald-500/70"
                    :style="{ width: width(row) }"
                />
                <span
                    v-else-if="gained(row) < 0"
                    class="absolute inset-y-0.5 right-1/2 rounded-l bg-red-600/70"
                    :style="{ width: width(row) }"
                />
            </div>

            <span
                class="shrink-0 text-right font-mono tabular-nums text-zinc-500"
                :class="isNarrow ? 'ml-auto text-xs' : 'w-20 text-[11px]'"
            >
                {{ row.from }} → {{ row.to }}
            </span>
            <span
                class="shrink-0 text-right font-mono font-semibold tabular-nums"
                :class="[
                    gained(row) > 0 ? 'text-emerald-400' : gained(row) < 0 ? 'text-red-500' : 'text-zinc-600',
                    isNarrow ? 'text-xs' : 'w-9 text-[11px]',
                ]"
            >
                {{ gained(row) > 0 ? '+' : '' }}{{ gained(row) }}
            </span>
        </li>
    </ul>
</template>
