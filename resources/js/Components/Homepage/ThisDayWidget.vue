<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    events: { type: Array, default: () => [] },
});

const has = (name) => {
    try {
        return route().has(name);
    } catch (e) {
        return false;
    }
};
</script>

<template>
    <section v-if="events.length" class="mt-10">
        <div class="mb-4 flex items-baseline gap-3">
            <h2 class="text-xl font-black sm:text-2xl">На този ден във F1</h2>
            <span class="text-sm text-zinc-500">състезания, проведени на днешната дата</span>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div
                v-for="(e, i) in events"
                :key="i"
                class="relative overflow-hidden rounded-xl border border-zinc-800 bg-zinc-900/60 p-4"
            >
                <div class="absolute inset-y-0 left-0 w-1" :style="{ backgroundColor: e.color ?? '#e10600' }" />
                <div class="flex items-center justify-between">
                    <span class="text-2xl font-black tabular-nums text-red-500">{{ e.year }}</span>
                    <span class="truncate pl-2 text-right text-xs text-zinc-500">{{ e.circuit }}</span>
                </div>
                <div class="mt-1 truncate font-semibold text-white">
                    <Link v-if="e.circuit_slug && has('circuits.show')" :href="route('circuits.show', e.circuit_slug)" class="transition hover:text-red-400">
                        {{ e.race }}
                    </Link>
                    <span v-else>{{ e.race }}</span>
                </div>
                <div class="mt-2 flex items-center gap-1.5 text-sm text-zinc-300">
                    <span class="text-amber-400">🏆</span>
                    <Link v-if="e.winner_slug && has('drivers.show')" :href="route('drivers.show', e.winner_slug)" class="font-medium transition hover:text-red-400">
                        {{ e.winner }}
                    </Link>
                    <span v-else class="font-medium">{{ e.winner ?? '—' }}</span>
                    <span v-if="e.team" class="text-zinc-500">· {{ e.team }}</span>
                </div>
            </div>
        </div>
    </section>
</template>
