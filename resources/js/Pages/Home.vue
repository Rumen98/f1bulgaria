<script setup>
import HeroSection from '@/Components/Hero/HeroSection.vue';
import ThisDayWidget from '@/Components/Homepage/ThisDayWidget.vue';
import LiveSessionBanner from '@/Components/LiveSessionBanner.vue';
import NewsCard from '@/Components/News/NewsCard.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { hasRoute } from '@/utils/routes';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    hero: { type: Object, required: true },
    liveSession: { type: Object, default: null },
    thisDay: { type: Array, default: () => [] },
    topNews: { type: Array, default: () => [] },
});

const page = usePage();
const features = computed(() => page.props.features ?? {});

const allLinks = [
    { label: 'Календар', desc: 'Всички състезания за сезона', route: 'calendar' },
    { label: 'Класиране', desc: 'Пилоти и конструктори', route: 'standings' },
    { label: 'Отбори', desc: '10-те отбора с branding и статистика', route: 'teams.index' },
    { label: 'Пилоти', desc: 'Профили, статистика, head-to-head', route: 'drivers.index' },
    { label: 'Писти', desc: 'История и all-time класирания', route: 'circuits.index', feature: 'circuits' },
    { label: 'Сравни пилоти', desc: 'Двама пилоти един до друг', route: 'compare.index', feature: 'compare' },
    { label: 'Съперничества', desc: 'Великите дуели в историята', route: 'rivalries.index', feature: 'rivalries' },
    { label: 'Prediction League', desc: 'Познай и събирай точки', route: 'leaderboard' },
];

const links = computed(() => allLinks.filter((l) => hasRoute(l.route) && (!l.feature || features.value[l.feature])));
</script>

<template>
    <Head title="Падок — Формула 1 на български">
        <meta head-key="description" name="description" content="Формула 1 на български: новини, календар на състезанията, класиране на пилоти и конструктори, статистика и профили. Независима общност на българските F1 фенове." />
    </Head>

    <PublicLayout>
        <h1 class="sr-only">Падок — новини, календар и класиране от Формула 1 на български</h1>

        <LiveSessionBanner v-if="features.live_timing" :session="liveSession" />

        <!-- Банер — визуален header над hero-а със състезанието -->
        <div class="relative mb-6 overflow-hidden rounded-2xl">
            <picture>
                <source media="(max-width: 767px)" srcset="/images/banners/hero/hero-750x800.webp" type="image/webp" />
                <source media="(max-width: 767px)" srcset="/images/banners/hero/hero-750x800.jpg" type="image/jpeg" />
                <source media="(max-width: 1279px)" srcset="/images/banners/hero/hero-1280x500.webp" type="image/webp" />
                <source media="(max-width: 1279px)" srcset="/images/banners/hero/hero-1280x500.jpg" type="image/jpeg" />
                <source media="(min-width: 1280px)" srcset="/images/banners/hero/hero-1920x600.webp" type="image/webp" />
                <img
                    src="/images/banners/hero/hero-1920x600.jpg"
                    alt="Падок — Формула 1 на български"
                    class="block h-auto w-full object-cover"
                    loading="eager"
                    fetchpriority="high"
                    width="1920"
                    height="600"
                />
            </picture>

            <div class="absolute inset-0 flex items-center">
                <div class="absolute inset-0 bg-gradient-to-r from-black/85 via-black/50 to-transparent" />
                <div class="relative max-w-4xl px-6 md:px-12 lg:px-20">
                    <p class="font-display text-4xl font-black leading-none tracking-tight text-white md:text-6xl lg:text-7xl">
                        Падок
                    </p>
                    <p class="mt-3 max-w-xl text-base font-medium text-white/90 md:text-xl lg:text-2xl">
                        Мястото на всички фенове на Формула 1 в България
                    </p>
                </div>
            </div>
        </div>

        <HeroSection :hero="hero" />

        <!-- На този ден във F1 (V2) -->
        <ThisDayWidget v-if="features.this_day" :events="thisDay" />

        <!-- Топ новини -->
        <section v-if="topNews.length" class="mt-10">
            <div class="mb-4 flex items-baseline justify-between">
                <h2 class="font-display text-xl font-black sm:text-2xl">Топ новини</h2>
                <Link v-if="hasRoute('news.index')" :href="route('news.index')" class="text-sm font-medium text-red-500 transition hover:text-red-400">
                    Всички новини →
                </Link>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <NewsCard v-for="(item, i) in topNews" :key="i" :item="item" />
            </div>
        </section>

        <!-- Разделите на платформата -->
        <section class="mt-10">
            <h2 class="mb-4 font-display text-xl font-black sm:text-2xl">Разгледай</h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="link in links"
                    :key="link.route"
                    :href="route(link.route)"
                    class="group rounded-xl border border-zinc-800 bg-zinc-900/60 p-5 transition duration-200 hover:border-red-600/50 hover:bg-zinc-900"
                >
                    <div class="font-semibold text-white transition group-hover:text-red-400">{{ link.label }}</div>
                    <div class="mt-1 text-sm text-zinc-500">{{ link.desc }}</div>
                </Link>
            </div>
        </section>
    </PublicLayout>
</template>
