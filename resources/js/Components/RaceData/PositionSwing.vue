<script setup>
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

const widest = computed(() =>
    Math.max(1, ...props.rows.map((row) => Math.abs(row.from - row.to))),
);

const width = (row) => `${(Math.abs(row.from - row.to) / widest.value) * 50}%`;

const gained = (row) => row.from - row.to;
</script>

<template>
    <ul class="space-y-1">
        <li v-for="row in rows" :key="row.number" class="flex items-center gap-2 text-sm">
            <span
                class="w-12 shrink-0 truncate font-mono text-[11px] font-semibold"
                :style="{ color: row.colour }"
                :title="row.name"
            >
                {{ row.short }}
            </span>

            <div class="relative h-4 grow">
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

            <span class="w-20 shrink-0 text-right font-mono text-[11px] tabular-nums text-zinc-500">
                {{ row.from }} → {{ row.to }}
            </span>
            <span
                class="w-9 shrink-0 text-right font-mono text-[11px] font-semibold tabular-nums"
                :class="gained(row) > 0 ? 'text-emerald-400' : gained(row) < 0 ? 'text-red-500' : 'text-zinc-600'"
            >
                {{ gained(row) > 0 ? '+' : '' }}{{ gained(row) }}
            </span>
        </li>
    </ul>
</template>
