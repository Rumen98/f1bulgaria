<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    circuit: { type: Object, required: true },
});
</script>

<template>
    <Link
        :href="route('circuits.show', circuit.slug)"
        class="group rounded-xl border border-zinc-800 bg-zinc-900/60 p-5 transition duration-200 hover:border-red-600/50 hover:bg-zinc-900"
    >
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
                <div class="truncate font-bold text-white transition group-hover:text-red-400">{{ circuit.name }}</div>
                <div class="text-sm text-zinc-500">{{ circuit.country }}</div>
            </div>
            <span
                class="flex-shrink-0 rounded-full px-2 py-0.5 text-xs font-bold uppercase tracking-wide"
                :class="circuit.is_active ? 'bg-emerald-500/15 text-emerald-400' : 'bg-zinc-700/40 text-zinc-400'"
            >
                {{ circuit.is_active ? 'Активна' : 'Историческа' }}
            </span>
        </div>
        <div class="mt-3 flex items-center justify-between text-xs text-zinc-500">
            <span>Последно: {{ circuit.last_race_year }}</span>
            <span v-if="circuit.has_track">🏁 track</span>
        </div>
    </Link>
</template>
