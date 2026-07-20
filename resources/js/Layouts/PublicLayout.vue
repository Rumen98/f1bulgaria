<script setup>
import NewsletterForm from '@/Components/Newsletter/NewsletterForm.vue';
import { hasRoute } from '@/utils/routes';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const flashSuccess = computed(() => page.props.flash?.success);

// Основна навигация (винаги видима на десктоп) + второстепенна (под „Повече ▾").
// Елементите с `feature` се показват само ако флагът е включен (config/features.php).
const primaryNav = [
    { label: 'На живо', route: 'live', live: true, feature: 'live_timing' },
    { label: 'Новини', route: 'news.index' },
    { label: 'Календар', route: 'calendar' },
    { label: 'Класиране', route: 'standings' },
    { label: 'Отбори', route: 'teams.index' },
    { label: 'Пилоти', route: 'drivers.index' },
    { label: 'Писти', route: 'circuits.index', feature: 'circuits' },
    { label: 'Цолов 🇧🇬', route: 'tsolov', feature: 'tsolov' },
];
const secondaryNav = [
    { label: 'Формула 2', route: 'f2', feature: 'f2' },
    { label: 'Дуели', route: 'rivalries.index', feature: 'rivalries' },
    { label: 'Прогнози', route: 'leaderboard' },
    { label: 'История', route: 'history', feature: 'history' },
    { label: 'Речник', route: 'terminology' },
];

const mobileOpen = ref(false);
const moreOpen = ref(false);
const moreRef = ref(null);

const features = computed(() => page.props.features ?? {});
// Показваме елемент само ако рутът съществува И (няма feature ИЛИ флагът е включен).
const visible = (i) => hasRoute(i.route) && (!i.feature || features.value[i.feature]);
const primary = computed(() => primaryNav.filter(visible));
const secondary = computed(() => secondaryNav.filter(visible));
const allItems = computed(() => [...primary.value, ...secondary.value]);

const onClickOutside = (e) => {
    if (moreRef.value && !moreRef.value.contains(e.target)) {
        moreOpen.value = false;
    }
};
onMounted(() => document.addEventListener('click', onClickOutside));
onBeforeUnmount(() => document.removeEventListener('click', onClickOutside));
</script>

<template>
    <div class="min-h-screen bg-[#0a0a0a] text-zinc-100">
        <header class="sticky top-0 z-30 border-b border-zinc-800/80 bg-black/70 backdrop-blur">
            <nav class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3.5">
                <Link :href="route('home')" class="flex items-center font-display text-lg font-black tracking-tight">
                    <span>Падок</span>
                </Link>

                <div class="hidden items-center gap-3 lg:flex xl:gap-5">
                    <Link
                        v-for="item in primary"
                        :key="item.route"
                        :href="route(item.route)"
                        class="flex items-center gap-1 whitespace-nowrap text-[13px] font-medium text-zinc-400 transition duration-200 hover:text-white xl:text-sm"
                        :class="{ 'text-white': route().current(item.route) }"
                    >
                        <span v-if="item.live" class="relative flex h-2 w-2">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-500 opacity-75" />
                            <span class="relative inline-flex h-2 w-2 rounded-full bg-red-600" />
                        </span>
                        {{ item.label }}
                    </Link>

                    <div v-if="secondary.length" ref="moreRef" class="relative">
                        <button
                            type="button"
                            class="flex items-center gap-1 whitespace-nowrap text-[13px] font-medium text-zinc-400 transition hover:text-white xl:text-sm"
                            aria-haspopup="true"
                            :aria-expanded="moreOpen"
                            @click="moreOpen = !moreOpen"
                        >
                            Повече ▾
                        </button>
                        <div v-if="moreOpen" class="absolute right-0 z-40 mt-2 w-44 rounded-xl border border-zinc-800 bg-zinc-950 p-1.5 shadow-2xl">
                            <Link
                                v-for="item in secondary"
                                :key="item.route"
                                :href="route(item.route)"
                                class="block rounded-lg px-3 py-2 text-sm text-zinc-300 transition hover:bg-zinc-800/60 hover:text-white"
                                :class="{ 'text-white': route().current(item.route) }"
                                @click="moreOpen = false"
                            >
                                {{ item.label }}
                            </Link>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3 text-sm">
                    <template v-if="user">
                        <Link :href="route('predictions.index')" class="hidden text-zinc-400 transition hover:text-white sm:block">
                            Моите прогнози
                        </Link>
                        <Link :href="route('profile.edit')" class="font-medium text-white">{{ user.name }}</Link>
                    </template>
                    <template v-else>
                        <Link :href="route('login')" class="text-zinc-400 transition hover:text-white">Вход</Link>
                        <Link
                            :href="route('register')"
                            class="rounded-md bg-red-600 px-3 py-1.5 font-medium text-white transition duration-200 hover:bg-red-500"
                        >
                            Регистрация
                        </Link>
                    </template>
                    <button
                        class="text-zinc-300 lg:hidden"
                        aria-label="Меню"
                        :aria-expanded="mobileOpen"
                        @click="mobileOpen = !mobileOpen"
                    >
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" /></svg>
                    </button>
                </div>
            </nav>

            <div v-show="mobileOpen" class="border-t border-zinc-800/80 lg:hidden">
                <div class="mx-auto flex max-w-6xl flex-col px-4 py-2">
                    <Link
                        v-for="item in allItems"
                        :key="item.route"
                        :href="route(item.route)"
                        class="flex items-center gap-1.5 py-2 text-sm font-medium text-zinc-300 transition hover:text-white"
                        @click="mobileOpen = false"
                    >
                        <span v-if="item.live" class="h-2 w-2 rounded-full bg-red-600" />
                        {{ item.label }}
                    </Link>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-4 py-8">
            <div
                v-if="flashSuccess"
                class="mb-6 rounded-lg border border-green-500/30 bg-green-500/10 px-4 py-3 text-sm text-green-300"
            >
                {{ flashSuccess }}
            </div>

            <slot />
        </main>

        <footer class="mt-12 border-t border-zinc-800 py-8 text-center text-sm text-zinc-500">
            <section class="relative mb-8 overflow-hidden rounded-2xl py-10 md:py-14">
                <picture class="absolute inset-0 z-0">
                    <source media="(max-width: 767px)" srcset="/images/banners/newsletter/newsletter-750x400.webp" type="image/webp" />
                    <source media="(max-width: 767px)" srcset="/images/banners/newsletter/newsletter-750x400.jpg" type="image/jpeg" />
                    <source media="(min-width: 768px)" srcset="/images/banners/newsletter/newsletter-1200x300.webp" type="image/webp" />
                    <img
                        src="/images/banners/newsletter/newsletter-1200x300.jpg"
                        alt=""
                        class="h-full w-full object-cover"
                        loading="lazy"
                        aria-hidden="true"
                    />
                </picture>

                <!-- Тъмен градиент за четимост на текста -->
                <div class="absolute inset-0 z-10 bg-gradient-to-r from-black/90 via-black/70 to-black/40" />

                <div class="relative z-20 mx-auto max-w-4xl px-6 text-left md:px-8">
                    <h3 class="mb-2 font-display text-2xl font-bold text-white md:text-3xl">
                        Не пропускай нито един уикенд от Формула 1
                    </h3>
                    <p class="mb-4 text-base text-white/85 md:mb-6 md:text-lg">
                        Абонирай се за бюлетина — прегледи преди старта, резултати и анализи.
                    </p>
                    <NewsletterForm source="footer" />
                </div>
            </section>
            <p class="mx-auto max-w-xl">
                Падок — независима общност на българските фенове на Формула 1.
            </p>
            <p class="mx-auto mt-2 max-w-xl text-xs text-zinc-500">
                Падок е независим фен проект и не е свързан с Formula One Group, FIA или отборите.
                F1® и FORMULA 1® са търговски марки на Formula One Licensing BV.
            </p>
            <nav class="mt-4 flex flex-wrap items-center justify-center gap-x-4 gap-y-2 text-xs text-zinc-500">
                <Link :href="route('privacy')" class="transition hover:text-zinc-300">Поверителност</Link>
                <span class="text-zinc-700" aria-hidden="true">·</span>
                <Link :href="route('terms')" class="transition hover:text-zinc-300">Условия за ползване</Link>
                <span class="text-zinc-700" aria-hidden="true">·</span>
                <Link :href="route('contact')" class="transition hover:text-zinc-300">Контакт</Link>
            </nav>
        </footer>
    </div>
</template>
