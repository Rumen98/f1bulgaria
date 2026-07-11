<script setup>
import { computed } from 'vue';

const props = defineProps({
    circuit: { type: Object, required: true },
    technical: { type: Object, default: null },
});

const svgs = import.meta.glob('../../../svg/circuits/*.svg', { query: '?raw', import: 'default', eager: true });

// Статичен track — без анимирания болид (за разлика от hero на началната).
const trackSvg = computed(() => {
    const key = Object.keys(svgs).find((p) => p.endsWith(`/${props.circuit.slug}.svg`));
    if (!key) {
        return null;
    }
    return svgs[key].replace(/<circle[\s\S]*?<\/circle>/i, '');
});

const meta = computed(() => [
    { label: 'Дължина', value: props.circuit.length_km ? props.circuit.length_km + ' км' : '—' },
    { label: 'Завои', value: props.circuit.turns ?? '—' },
    { label: 'Тип', value: props.circuit.type ?? '—' },
    { label: 'Първо F1', value: props.circuit.first_gp ?? '—' },
    { label: 'Състезания', value: props.circuit.races_count },
]);
</script>

<template>
    <section class="overflow-hidden rounded-2xl border border-zinc-800 bg-gradient-to-br from-zinc-900 via-zinc-950 to-black">
        <div class="grid lg:grid-cols-2">
            <div class="relative flex items-center justify-center p-6 lg:p-10">
                <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_70%_30%,rgba(225,6,0,0.12),transparent_60%)]" />
                <div
                    v-if="trackSvg"
                    class="relative w-full text-zinc-200 [&_svg]:mx-auto [&_svg]:h-auto [&_svg]:max-h-[40vh] [&_svg]:w-auto [&_svg]:max-w-full"
                    aria-hidden="true"
                    v-html="trackSvg"
                />
                <div v-else class="flex h-40 w-full items-center justify-center rounded-lg border border-dashed border-zinc-700 text-zinc-500">
                    {{ circuit.name }}
                </div>
            </div>

            <div class="flex flex-col justify-center gap-4 p-6 lg:p-10">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-red-500">{{ circuit.country }}</div>
                    <h1 class="mt-1 font-display text-3xl font-black sm:text-4xl">{{ circuit.name }}</h1>
                    <p v-if="technical && (technical.length_km || technical.lap_record_time)" class="mt-1 text-sm text-zinc-300">
                        <span v-if="technical.length_km" class="tabular-nums">{{ technical.length_km }} км</span>
                        <span v-if="technical.lap_record_time" class="text-zinc-500">
                            · ⏱️ {{ technical.lap_record_time }} ({{ technical.lap_record_driver }})
                        </span>
                    </p>
                    <p v-if="circuit.next_or_last_date" class="mt-1 text-sm text-zinc-400">
                        {{ circuit.next_or_last_label }}: {{ circuit.next_or_last_date }}
                    </p>
                </div>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <div v-for="m in meta" :key="m.label" class="rounded-lg border border-zinc-800 bg-black/30 p-3">
                        <div class="text-base font-bold text-white">{{ m.value }}</div>
                        <div class="text-xs uppercase tracking-wide text-zinc-500">{{ m.label }}</div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
