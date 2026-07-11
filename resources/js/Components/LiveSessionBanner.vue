<script setup>
import { Link } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    session: { type: Object, default: null },
});

const session = ref(props.session);
let timer = null;

// Проверява на всеки 60s дали сесията още е активна (за авто-скриване).
const poll = async () => {
    try {
        const res = await fetch(route('live.refresh'), { headers: { Accept: 'application/json' } });
        if (res.ok) {
            const data = await res.json();
            session.value = data.session ? { name: data.session.name, circuit: data.session.circuit } : null;
        }
    } catch (e) {
        // тихо
    }
};

onMounted(() => {
    timer = setInterval(poll, 60000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <Link
        v-if="session"
        :href="route('live')"
        class="mb-4 flex items-center justify-between gap-3 rounded-xl border border-red-600/40 bg-gradient-to-r from-red-950/60 to-zinc-950 px-4 py-3 transition hover:border-red-600"
    >
        <div class="flex items-center gap-3">
            <span class="relative flex h-3 w-3">
                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-500 opacity-75" />
                <span class="relative inline-flex h-3 w-3 rounded-full bg-red-600" />
            </span>
            <span class="sr-only">на живо</span>
            <span class="font-bold text-white">
                LIVE СЕГА: <span class="text-red-400">{{ session.circuit }}</span> · {{ session.name }}
            </span>
        </div>
        <span class="shrink-0 text-sm font-semibold text-red-400">Виж живо →</span>
    </Link>
</template>
