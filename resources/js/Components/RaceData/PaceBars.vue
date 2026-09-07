<script setup>
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

const max = computed(() => Math.max(0.001, ...props.rows.map((row) => row.delta)));

const width = (row) => `${Math.max(2, (row.delta / max.value) * 100)}%`;
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

            <div class="h-3 grow overflow-hidden rounded bg-zinc-800/60">
                <span class="block h-full opacity-80" :style="{ width: width(row), backgroundColor: row.colour }" />
            </div>

            <span class="w-20 shrink-0 text-right font-mono text-[11px] tabular-nums text-zinc-500">
                {{ lapTime(row.median) }}
            </span>
            <span class="w-14 shrink-0 text-right font-mono text-[11px] tabular-nums text-zinc-400">
                {{ row.delta === 0 ? '—' : `+${bg(row.delta, 3)}` }}
            </span>
        </li>
    </ul>
</template>
