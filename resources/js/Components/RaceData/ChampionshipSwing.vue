<script setup>
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

const max = computed(() => Math.max(1, ...props.rows.map((row) => row.after)));

const pct = (value) => `${(value / max.value) * 100}%`;
</script>

<template>
    <ul class="space-y-1.5">
        <li v-for="row in rows" :key="row.number" class="flex items-center gap-2 text-sm">
            <span class="w-5 shrink-0 text-right font-mono text-[11px] tabular-nums text-zinc-600">
                {{ row.position }}
            </span>
            <span
                class="shrink-0 truncate font-mono text-[11px] font-semibold"
                :class="labels === 'wide' ? 'w-24' : 'w-12'"
                :style="{ color: row.colour }"
                :title="row.name"
            >
                {{ row.short }}
            </span>

            <div class="relative h-4 grow overflow-hidden rounded bg-zinc-800/60">
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

            <span class="w-10 shrink-0 text-right font-mono text-[11px] tabular-nums text-zinc-300">
                {{ row.after }}
            </span>
            <span
                class="w-8 shrink-0 text-right font-mono text-[11px] tabular-nums"
                :class="row.after > row.before ? 'text-emerald-400' : 'text-zinc-700'"
            >
                {{ row.after > row.before ? `+${row.after - row.before}` : '—' }}
            </span>
        </li>
    </ul>
</template>
