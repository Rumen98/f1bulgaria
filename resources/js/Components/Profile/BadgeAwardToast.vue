<script setup>
import { badgeArt } from '@/utils/badgeArt';
import { hasRoute } from '@/utils/routes';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Поздравление за нова значка — показва се при първото зареждане след
 * присъждането и изчезва чак когато потребителят го затвори (или отвори
 * профила си от него). Няма авто-затваряне по таймер: значка се печели
 * рядко и заслужава да бъде видяна, не да изтече, докато човекът чете.
 */
const page = usePage();

const badges = computed(() => page.props.newBadges ?? []);
const user = computed(() => page.props.auth?.user);

const visible = ref(false);

// Малко закъснение след mount: тостът се плъзга, след като страницата вече
// стои — иначе се губи в потока на първоначалното рендиране.
let enterTimer = null;
onMounted(() => {
    enterTimer = setTimeout(() => (visible.value = true), 600);
});
onBeforeUnmount(() => clearTimeout(enterTimer));

const dismiss = () => {
    visible.value = false;

    // POST-ът маркира всички невидени; back() опреснява props и v-if-ът
    // сваля компонента. Ако заявката се загуби (затворен таб по средата),
    // тостът просто ще се появи пак следващия път — нищо не се губи.
    router.post(route('badges.seen'), {}, { preserveScroll: true, preserveState: true });
};

const profileUrl = computed(() =>
    user.value && hasRoute('profiles.show') ? route('profiles.show', user.value.id) : null,
);
</script>

<template>
    <div
        v-if="badges.length && user"
        class="fixed inset-x-3 bottom-3 z-50 transition-all duration-500 motion-reduce:transition-none sm:inset-x-auto sm:right-4 sm:bottom-4 sm:w-96"
        :class="visible ? 'translate-y-0 opacity-100' : 'translate-y-4 opacity-0'"
        role="status"
        aria-live="polite"
    >
        <div class="overflow-hidden rounded-2xl border border-zinc-700 bg-zinc-900 shadow-2xl shadow-black/60">
            <!-- Шахматна лента — същият празничен език като финала на куиза. -->
            <div class="flag h-1.5" aria-hidden="true" />

            <div class="p-4">
                <div class="mb-3 flex items-start justify-between gap-3">
                    <p class="font-display text-xs font-black uppercase tracking-[0.2em] text-amber-400">
                        {{ badges.length === 1 ? 'Нова значка!' : `${badges.length} нови значки!` }}
                    </p>
                    <button
                        type="button"
                        class="-m-1 rounded p-1 text-zinc-500 transition hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-red-500"
                        aria-label="Затвори известието"
                        @click="dismiss"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <ul class="space-y-3">
                    <li v-for="badge in badges" :key="badge.slug" class="flex items-center gap-3">
                        <span
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-gradient-to-br text-xl shadow-lg"
                            :class="[badgeArt(badge.slug).circle, badgeArt(badge.slug).glow]"
                            aria-hidden="true"
                        >
                            {{ badgeArt(badge.slug).emoji }}
                        </span>
                        <div class="min-w-0">
                            <p class="font-display text-sm font-black text-white">{{ badge.name }}</p>
                            <p class="text-xs leading-snug text-zinc-400">{{ badge.description }}</p>
                        </div>
                    </li>
                </ul>

                <div v-if="profileUrl" class="mt-4">
                    <Link
                        :href="profileUrl"
                        class="block rounded-lg bg-red-600 px-4 py-2 text-center text-sm font-semibold text-white transition hover:bg-red-500"
                        @click="dismiss"
                    >
                        Виж значките си →
                    </Link>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.flag {
    background-image: repeating-conic-gradient(#fff 0deg 90deg, #18181b 90deg 180deg);
    background-size: 10px 10px;
}
</style>
