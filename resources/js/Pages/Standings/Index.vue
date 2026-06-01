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
        <h1 class="mb-6 text-2xl font-bold">Класиране {{ season }}</h1>

        <div class="mb-6 inline-flex rounded-lg border border-gray-200 bg-white p-1">
            <button
                class="rounded-md px-4 py-1.5 text-sm font-medium transition"
                :class="tab === 'drivers' ? 'bg-gray-900 text-white' : 'text-gray-600'"
                @click="tab = 'drivers'"
            >
                Пилоти
            </button>
            <button
                class="rounded-md px-4 py-1.5 text-sm font-medium transition"
                :class="tab === 'constructors' ? 'bg-gray-900 text-white' : 'text-gray-600'"
                @click="tab = 'constructors'"
            >
                Конструктори
            </button>
        </div>

        <div v-if="tab === 'drivers'" class="overflow-hidden rounded-lg border border-gray-200 bg-white">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-3">#</th>
                        <th class="px-4 py-3">Пилот</th>
                        <th class="px-4 py-3">Отбор</th>
                        <th class="px-4 py-3 text-center">Победи</th>
                        <th class="px-4 py-3 text-right">Точки</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="row in drivers" :key="row.driver.id">
                        <td class="px-4 py-3 font-medium text-gray-500">{{ row.position }}</td>
                        <td class="px-4 py-3 font-semibold">
                            {{ row.driver.full_name }}
                            <span class="ml-1 text-xs text-gray-400">{{ row.driver.code }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <span
                                class="inline-flex items-center gap-2"
                            >
                                <span
                                    v-if="row.driver.constructor?.color_hex"
                                    class="h-3 w-3 rounded-full"
                                    :style="{ backgroundColor: row.driver.constructor.color_hex }"
                                />
                                {{ row.driver.constructor?.name ?? '—' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center">{{ row.wins }}</td>
                        <td class="px-4 py-3 text-right font-bold">{{ row.points }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-else class="overflow-hidden rounded-lg border border-gray-200 bg-white">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-3">#</th>
                        <th class="px-4 py-3">Конструктор</th>
                        <th class="px-4 py-3 text-center">Победи</th>
                        <th class="px-4 py-3 text-right">Точки</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="row in constructors" :key="row.constructor.id">
                        <td class="px-4 py-3 font-medium text-gray-500">{{ row.position }}</td>
                        <td class="px-4 py-3 font-semibold">
                            <span class="inline-flex items-center gap-2">
                                <span
                                    v-if="row.constructor.color_hex"
                                    class="h-3 w-3 rounded-full"
                                    :style="{ backgroundColor: row.constructor.color_hex }"
                                />
                                {{ row.constructor.name }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center">{{ row.wins }}</td>
                        <td class="px-4 py-3 text-right font-bold">{{ row.points }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </PublicLayout>
</template>
