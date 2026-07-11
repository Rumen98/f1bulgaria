<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const open = ref(false);
const copied = ref(false);
const root = ref(null);

// Абсолютен .ics URL (на клиента, за да хване правилния домейн/порт).
const origin = typeof window !== 'undefined' ? window.location.origin : '';
const icsUrl = computed(() => `${origin}/calendar.ics`);
const webcalUrl = computed(() => icsUrl.value.replace(/^https?:/, 'webcal:'));
const googleUrl = computed(() => `https://calendar.google.com/calendar/r?cid=${encodeURIComponent(webcalUrl.value)}`);

const copy = async () => {
    try {
        await navigator.clipboard.writeText(icsUrl.value);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch (e) {
        // тихо — ако clipboard не е достъпен, потребителят вижда URL-а да го копира ръчно
    }
};

const onClickOutside = (e) => {
    if (root.value && !root.value.contains(e.target)) {
        open.value = false;
    }
};
const onKeydown = (e) => {
    if (e.key === 'Escape') {
        open.value = false;
    }
};
onMounted(() => {
    document.addEventListener('click', onClickOutside);
    document.addEventListener('keydown', onKeydown);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', onClickOutside);
    document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-800 bg-zinc-900/60 px-3 py-2 text-sm font-medium text-zinc-200 transition hover:border-red-600 hover:text-white"
            aria-haspopup="true"
            :aria-expanded="open"
            @click="open = !open"
        >
            🗓️ Абонирай се
        </button>

        <div
            v-if="open"
            class="absolute right-0 z-20 mt-2 w-72 rounded-xl border border-zinc-800 bg-zinc-950 p-4 shadow-2xl"
        >
            <p class="mb-3 text-xs text-zinc-400">Добави F1 календара в твоето приложение — сесиите се обновяват автоматично.</p>

            <div class="flex items-center gap-2">
                <input
                    :value="icsUrl"
                    readonly
                    aria-label="Линк за абонамент за календара"
                    class="min-w-0 flex-1 truncate rounded-lg border border-zinc-800 bg-zinc-900 px-2 py-1.5 text-xs text-zinc-300"
                />
                <button
                    type="button"
                    class="shrink-0 rounded-lg bg-red-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-red-500"
                    @click="copy"
                >
                    {{ copied ? '✓' : 'Копирай' }}
                </button>
                <span aria-live="polite" class="sr-only">{{ copied ? 'Копирано' : '' }}</span>
            </div>

            <div class="mt-3 grid gap-2">
                <a
                    :href="googleUrl"
                    target="_blank"
                    rel="noopener"
                    class="rounded-lg border border-zinc-800 px-3 py-2.5 text-center text-sm font-medium text-zinc-200 transition hover:border-zinc-600 hover:text-white"
                >
                    Добави в Google Calendar
                </a>
                <a
                    :href="webcalUrl"
                    class="rounded-lg border border-zinc-800 px-3 py-2.5 text-center text-sm font-medium text-zinc-200 transition hover:border-zinc-600 hover:text-white"
                >
                    Добави в Apple Calendar
                </a>
            </div>
        </div>
    </div>
</template>
