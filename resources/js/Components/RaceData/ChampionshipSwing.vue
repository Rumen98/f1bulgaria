<script setup>
import { useChartBox } from '@/composables/useChartBox';
import { computed } from 'vue';

/**
 * Шампионатът преди и след кръга.
 *
 * Лентата е точките ПРЕДИ състезанието, а по-светлият край е спечеленото в
 * него — така се вижда едновременно къде е човекът и колко му е донесла
 * неделята. Две заявки към OpenF1 за най-разказваемата графика в набора.
 */
const props = defineProps({
    rows: { type: Array, required: true },
    /**
     * Колоната с етикета е за трибуквени кодове на пилоти. Имената на отборите
     * са думи и се режат в нея — затова при конструкторите се подава 'wide'.
     */
    labels: { type: String, default: 'short' },
});

// На тясно фиксираната колона за име (96 пиксела при отборите) заедно с
// числата не оставя място за лентата. Затова там редът се пречупва: имената
// горе, лентата отдолу през цялата ширина.
const { host, isNarrow } = useChartBox();

const max = computed(() => Math.max(1, ...props.rows.map((row) => row.after)));

const pct = (value) => `${(value / max.value) * 100}%`;
</script>

<template>
    <ul ref="host" :class="isNarrow ? 'space-y-3' : 'space-y-1.5'">
        <li v-for="row in rows" :key="row.number" class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
            <span
                class="w-5 shrink-0 text-right font-mono tabular-nums text-zinc-600"
                :class="isNarrow ? 'text-xs' : 'text-[11px]'"
            >
                {{ row.position }}
            </span>
            <span
                class="min-w-0 truncate font-mono font-semibold"
                :class="[
                    isNarrow ? 'text-xs' : 'text-[11px]',
                    isNarrow ? '' : labels === 'wide' ? 'w-24 shrink-0' : 'w-12 shrink-0',
                ]"
                :style="{ color: row.colour }"
                :title="row.name"
            >
                {{ row.short }}
            </span>

            <div
                class="relative h-4 overflow-hidden rounded bg-zinc-800/60"
                :class="isNarrow ? 'order-last w-full' : 'grow'"
            >
                <span
                    class="absolute inset-y-0 left-0 opacity-70"
                    :style="{ width: pct(row.before), backgroundColor: row.colour }"
                />
                <!-- Спечеленото в този кръг е същият цвят, но плътен — окото го
                     чете като „нова“ част от лентата, не като друга серия. -->
                <span
                    class="absolute inset-y-0"
                    :style="{ left: pct(row.before), width: pct(Math.max(0, row.after - row.before)), backgroundColor: row.colour }"
                />
            </div>

            <span
                class="shrink-0 text-right font-mono tabular-nums text-zinc-300"
                :class="isNarrow ? 'ml-auto text-xs' : 'w-10 text-[11px]'"
            >
                {{ row.after }}
            </span>
            <span
                class="shrink-0 text-right font-mono tabular-nums"
                :class="[
                    row.after > row.before ? 'text-emerald-400' : 'text-zinc-700',
                    isNarrow ? 'text-xs' : 'w-8 text-[11px]',
                ]"
            >
                {{ row.after > row.before ? `+${row.after - row.before}` : '—' }}
            </span>
        </li>
    </ul>
</template>
