<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const flashSuccess = computed(() => page.props.flash?.success);

const navItems = [
    { label: 'Календар', route: 'calendar' },
    { label: 'Класиране', route: 'standings' },
    { label: 'Прогнози', route: 'leaderboard' },
];
</script>

<template>
    <div class="min-h-screen bg-gray-50 text-gray-900">
        <header class="bg-gray-900 text-white">
            <nav class="mx-auto flex max-w-6xl items-center justify-between px-4 py-4">
                <Link :href="route('home')" class="flex items-center gap-2 text-lg font-bold tracking-tight">
                    <span class="text-red-500">F1</span> България
                </Link>

                <div class="hidden gap-6 sm:flex">
                    <Link
                        v-for="item in navItems"
                        :key="item.route"
                        :href="route(item.route)"
                        class="text-sm font-medium text-gray-300 transition hover:text-white"
                        :class="{ 'text-white': route().current(item.route) }"
                    >
                        {{ item.label }}
                    </Link>
                </div>

                <div class="flex items-center gap-4 text-sm">
                    <template v-if="user">
                        <Link :href="route('predictions.index')" class="text-gray-300 hover:text-white">
                            Моите прогнози
                        </Link>
                        <Link :href="route('profile.edit')" class="font-medium text-white">
                            {{ user.name }}
                        </Link>
                    </template>
                    <template v-else>
                        <Link :href="route('login')" class="text-gray-300 hover:text-white">Вход</Link>
                        <Link
                            :href="route('register')"
                            class="rounded-md bg-red-600 px-3 py-1.5 font-medium text-white hover:bg-red-500"
                        >
                            Регистрация
                        </Link>
                    </template>
                </div>
            </nav>
        </header>

        <main class="mx-auto max-w-6xl px-4 py-8">
            <div
                v-if="flashSuccess"
                class="mb-6 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"
            >
                {{ flashSuccess }}
            </div>

            <slot />
        </main>

        <footer class="mt-12 border-t border-gray-200 py-8 text-center text-sm text-gray-500">
            F1 България — общност на българските фенове на Формула 1.
            Live чатът е в нашия <a href="#" class="text-red-600 hover:underline">Telegram канал</a>.
        </footer>
    </div>
</template>
