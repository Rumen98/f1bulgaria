<script setup>
import SurveyPromptCard from '@/Components/Feedback/SurveyPromptCard.vue';
import FlagIcon from '@/Components/FlagIcon.vue';
import NewsletterForm from '@/Components/Newsletter/NewsletterForm.vue';
import { hasRoute } from '@/utils/routes';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const flashSuccess = computed(() => page.props.flash?.success);
// Картата-подкана за обратна връзка (SurveyPromptService решава кога).
// На /obratna-vrazka не се дублира с формата на самата страница.
const showSurveyPrompt = computed(
    () => Boolean(page.props.survey?.shouldPrompt) && !page.url.startsWith('/obratna-vrazka'),
);

// Основна навигация (винаги видима на десктоп) + справочна (под „Енциклопедия ▾").
// Елементите с `feature` се показват само ако флагът е включен (config/features.php).
// Принцип на разделяне: „живото" (следиш сезона, играеш) е отпред; справочното
// съдържание без времева стойност живее в „Енциклопедия".
const primaryNav = [
    { label: 'На живо', route: 'live', live: true, feature: 'live_timing' },
    { label: 'Новини', route: 'news.index' },
    { label: 'Календар', route: 'calendar' },
    { label: 'Класиране', route: 'standings' },
    // Prediction league-ът е ядро на общността — стои в основната лента.
    { label: 'Прогнози', route: 'leaderboard' },
    { label: 'Куиз', route: 'quiz', feature: 'quiz' },
    // Ф2 до Цолов — двете вървят заедно.
    { label: 'Формула 2', route: 'f2', feature: 'f2' },
    // tricolor: easter egg — името светва в бг трибагреника на hover.
    { label: 'Цолов', route: 'tsolov', feature: 'tsolov', flag: 'bg', tricolor: true },
];
const secondaryNav = [
    { label: 'Отбори', route: 'teams.index' },
    { label: 'Пилоти', route: 'drivers.index' },
    { label: 'Писти', route: 'circuits.index', feature: 'circuits' },
    { label: 'Дуели', route: 'rivalries.index', feature: 'rivalries' },
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
                        class="group flex items-center gap-1 whitespace-nowrap text-[13px] font-medium text-zinc-400 transition duration-200 hover:text-white xl:text-sm"
                        :class="{ 'text-white': route().current(item.route) }"
                    >
                        <span v-if="item.live" class="relative flex h-2 w-2">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-500 opacity-75" />
                            <span class="relative inline-flex h-2 w-2 rounded-full bg-red-600" />
                        </span>
                        <span :class="{ 'group-hover:bg-gradient-to-b group-hover:from-white group-hover:via-green-500 group-hover:to-red-600 group-hover:bg-clip-text group-hover:text-transparent': item.tricolor }">
                            {{ item.label }}
                        </span>
                        <FlagIcon v-if="item.flag" :code="item.flag" class="text-[11px]" />
                    </Link>

                    <div v-if="secondary.length" ref="moreRef" class="relative">
                        <button
                            type="button"
                            class="flex items-center gap-1 whitespace-nowrap text-[13px] font-medium text-zinc-400 transition hover:text-white xl:text-sm"
                            aria-haspopup="true"
                            :aria-expanded="moreOpen"
                            @click="moreOpen = !moreOpen"
                        >
                            Енциклопедия ▾
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
                        <!-- xl: на lg лентата с 8 линка + „Енциклопедия" не побира и този — има го и в /leaderboard. -->
                        <Link :href="route('predictions.index')" class="hidden text-zinc-400 transition hover:text-white xl:block">
                            Моите прогнози
                        </Link>
                        <Link :href="route('profile.edit')" class="max-w-[140px] truncate font-medium text-white">{{ user.name }}</Link>
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
                        <FlagIcon v-if="item.flag" :code="item.flag" class="text-[11px]" />
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

            <SurveyPromptCard v-if="showSurveyPrompt" class="mb-6" />

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
            <div class="mt-5 flex items-center justify-center gap-4">
                <a
                    href="https://www.facebook.com/padokbg"
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label="Падок във Facebook"
                    class="text-zinc-500 transition hover:text-white"
                >
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06c0 5.02 3.66 9.18 8.44 9.94v-7.03H7.9v-2.91h2.54V9.85c0-2.52 1.5-3.92 3.79-3.92 1.1 0 2.24.2 2.24.2v2.47h-1.26c-1.24 0-1.63.78-1.63 1.57v1.89h2.78l-.45 2.91h-2.33V22c4.78-.76 8.44-4.92 8.44-9.94Z" />
                    </svg>
                </a>
                <a
                    href="https://www.instagram.com/padokbg"
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label="Падок в Instagram"
                    class="text-zinc-500 transition hover:text-white"
                >
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <rect x="3" y="3" width="18" height="18" rx="5" />
                        <circle cx="12" cy="12" r="4" />
                        <circle cx="17.2" cy="6.8" r="0.8" fill="currentColor" stroke="none" />
                    </svg>
                </a>
                <a
                    href="https://t.me/padokbg"
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label="Падок в Telegram"
                    class="text-zinc-500 transition hover:text-white"
                >
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M12 0a12 12 0 1 0 0 24 12 12 0 0 0 0-24Zm4.906 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212-.07-.062-.174-.041-.249-.024-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635Z" />
                    </svg>
                </a>
            </div>
            <nav class="mt-4 flex flex-wrap items-center justify-center gap-x-4 gap-y-2 text-xs text-zinc-500">
                <Link :href="route('privacy')" class="transition hover:text-zinc-300">Поверителност</Link>
                <span class="text-zinc-700" aria-hidden="true">·</span>
                <Link :href="route('terms')" class="transition hover:text-zinc-300">Условия за ползване</Link>
                <span class="text-zinc-700" aria-hidden="true">·</span>
                <Link :href="route('contact')" class="transition hover:text-zinc-300">Контакт</Link>
                <!-- Само за логнати — формата изисква акаунт, а гост би отскочил в login. -->
                <template v-if="user">
                    <span class="text-zinc-700" aria-hidden="true">·</span>
                    <Link :href="route('feedback')" class="transition hover:text-zinc-300">Обратна връзка</Link>
                </template>
            </nav>
        </footer>
    </div>
</template>
