<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    season: Number,
    drivers: Array,
    constructors: Array,
});

const tab = ref('drivers');
</script>

<template>
    <Head title="Класиране" />

    <PublicLayout>
        <h1 class="mb-6 text-2xl font-black sm:text-3xl">Класиране <span class="text-red-600">{{ season }}</span></h1>

        <div class="mb-6 inline-flex rounded-lg border border-zinc-800 bg-zinc-900/60 p-1">
            <button
                class="rounded-md px-4 py-1.5 text-sm font-medium transition duration-200"
                :class="tab === 'drivers' ? 'bg-red-600 text-white' : 'text-zinc-400 hover:text-white'"
                @click="tab = 'drivers'"
            >
                Пилоти
            </button>
            <button
                class="rounded-md px-4 py-1.5 text-sm font-medium transition duration-200"
                :class="tab === 'constructors' ? 'bg-red-600 text-white' : 'text-zinc-400 hover:text-white'"
                @click="tab = 'constructors'"
            >
                Конструктори
            </button>
        </div>

        <div v-if="tab === 'drivers'" class="overflow-hidden rounded-xl border border-zinc-800">
            <table class="w-full text-sm">
                <thead class="bg-zinc-900 text-left text-xs uppercase tracking-wide text-zinc-500">
                    <tr>
                        <th class="px-4 py-3 w-12">#</th>
                        <th class="px-4 py-3">Пилот</th>
                        <th class="px-4 py-3">Отбор</th>
                        <th class="px-4 py-3 text-center">Победи</th>
                        <th class="px-4 py-3 text-right">Точки</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800">
                    <tr v-for="row in drivers" :key="row.driver.id" class="bg-zinc-900/40 transition duration-200 hover:bg-zinc-800/50">
                        <td class="px-4 py-3 font-bold tabular-nums text-zinc-500">{{ row.position }}</td>
                        <td class="px-4 py-3 font-semibold text-white">
                            {{ row.driver.full_name }}
                            <span class="ml-1 text-xs font-normal text-zinc-500">{{ row.driver.code }}</span>
                        </td>
                        <td class="px-4 py-3 text-zinc-300">
                            <span class="inline-flex items-center gap-2">
                                <span
                                    class="h-3 w-3 rounded-full"
                                    :style="{ backgroundColor: row.driver.constructor?.color_hex ?? '#52525b' }"
                                />
                                {{ row.driver.constructor?.name ?? '—' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center tabular-nums text-zinc-300">{{ row.wins }}</td>
                        <td class="px-4 py-3 text-right font-bold tabular-nums text-white">{{ row.points }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-else class="overflow-hidden rounded-xl border border-zinc-800">
            <table class="w-full text-sm">
                <thead class="bg-zinc-900 text-left text-xs uppercase tracking-wide text-zinc-500">
                    <tr>
                        <th class="px-4 py-3 w-12">#</th>
                        <th class="px-4 py-3">Конструктор</th>
                        <th class="px-4 py-3 text-center">Победи</th>
                        <th class="px-4 py-3 text-right">Точки</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800">
                    <tr v-for="row in constructors" :key="row.constructor.id" class="bg-zinc-900/40 transition duration-200 hover:bg-zinc-800/50">
                        <td class="px-4 py-3 font-bold tabular-nums text-zinc-500">{{ row.position }}</td>
                        <td class="px-4 py-3 font-semibold text-white">
                            <span class="inline-flex items-center gap-2">
                                <span class="h-3 w-6 rounded-sm" :style="{ backgroundColor: row.constructor.color_hex ?? '#52525b' }" />
                                {{ row.constructor.name }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center tabular-nums text-zinc-300">{{ row.wins }}</td>
                        <td class="px-4 py-3 text-right font-bold tabular-nums text-white">{{ row.points }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </PublicLayout>
</template>
