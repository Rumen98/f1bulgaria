<script setup>
import NewsletterForm from '@/Components/Newsletter/NewsletterForm.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const flashSuccess = computed(() => page.props.flash?.success);

const navItems = [
    { label: 'Новини', route: 'news.index' },
    { label: 'Календар', route: 'calendar' },
    { label: 'Класиране', route: 'standings' },
    { label: 'Отбори', route: 'teams.index' },
    { label: 'Пилоти', route: 'drivers.index' },
    { label: 'Писти', route: 'circuits.index' },
    { label: 'Дуели', route: 'rivalries.index' },
    { label: 'Прогнози', route: 'leaderboard' },
    { label: 'История', route: 'history' },
    { label: 'Цолов 🇧🇬', route: 'tsolov' },
];

const mobileOpen = ref(false);

// Ziggy може да не познава всички routes по време на изграждане — пазим се.
const has = (name) => {
    try {
        return route().has(name);
    } catch (e) {
        return false;
    }
};
const items = computed(() => navItems.filter((i) => has(i.route)));
</script>

<template>
    <div class="min-h-screen bg-[#0a0a0a] text-zinc-100">
        <header class="sticky top-0 z-30 border-b border-zinc-800/80 bg-black/70 backdrop-blur">
            <nav class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3.5">
                <Link :href="route('home')" class="flex items-center gap-1.5 text-lg font-black tracking-tight">
                    <span class="text-red-600">F1</span><span>България</span>
                </Link>

                <div class="hidden items-center gap-6 lg:flex">
                    <Link
                        v-for="item in items"
                        :key="item.route"
                        :href="route(item.route)"
                        class="text-sm font-medium text-zinc-400 transition duration-200 hover:text-white"
                        :class="{ 'text-white': route().current(item.route) }"
                    >
                        {{ item.label }}
                    </Link>
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
                        @click="mobileOpen = !mobileOpen"
                    >
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" /></svg>
                    </button>
                </div>
            </nav>

            <div v-show="mobileOpen" class="border-t border-zinc-800/80 lg:hidden">
                <div class="mx-auto flex max-w-6xl flex-col px-4 py-2">
                    <Link
                        v-for="item in items"
                        :key="item.route"
                        :href="route(item.route)"
                        class="py-2 text-sm font-medium text-zinc-300 transition hover:text-white"
                        @click="mobileOpen = false"
                    >
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
            <div class="mb-6">
                <p class="mb-3 font-semibold text-zinc-300">Бюлетин с най-важното от F1</p>
                <NewsletterForm source="footer" />
            </div>
            F1 България — общност на българските фенове на Формула 1.
            Live чатът е в нашия <a href="#" class="text-red-500 transition hover:text-red-400">Telegram канал</a>.
        </footer>
    </div>
</template>
