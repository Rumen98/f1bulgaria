<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
});
</script>

<template>
    <div class="flex min-h-screen bg-[#0a0a0a] text-white">
        <!-- Форма -->
        <div class="flex w-full flex-col justify-center px-6 py-12 sm:px-12 lg:w-1/2 lg:px-16">
            <div class="mx-auto w-full max-w-md">
                <Link href="/" class="inline-flex items-center font-display text-2xl font-black tracking-tight">
                    Падок<span class="text-red-600">.</span>
                </Link>

                <div class="mt-8">
                    <h1 v-if="title" class="font-display text-2xl font-black sm:text-3xl">{{ title }}</h1>
                    <p v-if="subtitle" class="mt-2 text-sm text-zinc-400">{{ subtitle }}</p>
                </div>

                <div class="mt-8">
                    <slot />
                </div>
            </div>
        </div>

        <!-- Визуален hero (само на голям екран) -->
        <div class="relative hidden overflow-hidden bg-gradient-to-br from-zinc-900 via-zinc-950 to-black lg:flex lg:w-1/2">
            <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_70%_30%,rgba(225,6,0,0.18),transparent_60%)]" />

            <!-- Чертаещи се състезателни линии -->
            <svg class="absolute inset-0 h-full w-full" viewBox="0 0 600 800" fill="none" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
                <path
                    d="M-50 200 C 150 120, 250 320, 420 240 S 680 180, 720 360"
                    stroke="#e10600" stroke-width="3" stroke-linecap="round"
                    style="stroke-dasharray: 1600; animation: auth-line-draw 7s ease-in-out infinite;"
                />
                <path
                    d="M-50 460 C 120 380, 320 560, 460 460 S 660 420, 740 560"
                    stroke="#3f3f46" stroke-width="2" stroke-linecap="round"
                    style="stroke-dasharray: 1600; animation: auth-line-draw 9s ease-in-out infinite; animation-delay: 1.2s;"
                />
                <path
                    d="M-50 640 C 200 600, 300 740, 500 660 S 680 620, 760 720"
                    stroke="#e10600" stroke-width="1.5" stroke-linecap="round" opacity="0.5"
                    style="stroke-dasharray: 1600; animation: auth-line-draw 11s ease-in-out infinite; animation-delay: 2.4s;"
                />
            </svg>

            <div class="relative mt-auto p-12">
                <div class="font-display text-3xl font-black leading-tight">
                    Прогнози.<br />Класирания.<br /><span class="text-red-600">История.</span>
                </div>
                <p class="mt-4 max-w-sm text-sm text-zinc-400">
                    Общността на българските фенове на Формула 1 — от Фанджо до днес.
                </p>
            </div>
        </div>
    </div>
</template>
