<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    raceId: Number,
    drivers: Array,
    prediction: Object,
    locked: Boolean,
});

const form = useForm({
    p1_driver_id: props.prediction?.p1_driver_id ?? null,
    p2_driver_id: props.prediction?.p2_driver_id ?? null,
    p3_driver_id: props.prediction?.p3_driver_id ?? null,
    pole_driver_id: props.prediction?.pole_driver_id ?? null,
    fastest_lap_driver_id: props.prediction?.fastest_lap_driver_id ?? null,
    dnf_count: props.prediction?.dnf_count ?? 0,
    safety_car: props.prediction?.safety_car ?? false,
});

const driverFields = [
    { key: 'p1_driver_id', label: '🥇 Победител (P1)' },
    { key: 'p2_driver_id', label: '🥈 Втори (P2)' },
    { key: 'p3_driver_id', label: '🥉 Трети (P3)' },
    { key: 'pole_driver_id', label: '⏱️ Pole позиция' },
    { key: 'fastest_lap_driver_id', label: '🔥 Най-бърза обиколка' },
];

const submit = () => {
    form.post(route('predictions.store', props.raceId), {
        preserveScroll: true,
    });
};
</script>

<template>
    <form class="space-y-5" @submit.prevent="submit">
        <div v-for="field in driverFields" :key="field.key">
            <InputLabel :for="field.key" :value="field.label" />
            <select
                :id="field.key"
                v-model="form[field.key]"
                :disabled="locked"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500 disabled:bg-gray-100"
            >
                <option :value="null">— избери пилот —</option>
                <option v-for="driver in drivers" :key="driver.id" :value="driver.id">
                    {{ driver.name }}
                </option>
            </select>
            <InputError class="mt-1" :message="form.errors[field.key]" />
        </div>

        <div>
            <InputLabel for="dnf_count" value="Брой отпаднали (DNF)" />
            <input
                id="dnf_count"
                v-model.number="form.dnf_count"
                type="number"
                min="0"
                max="20"
                :disabled="locked"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500 disabled:bg-gray-100"
            />
            <InputError class="mt-1" :message="form.errors.dnf_count" />
        </div>

        <label class="flex items-center gap-2">
            <input
                v-model="form.safety_car"
                type="checkbox"
                :disabled="locked"
                class="rounded border-gray-300 text-red-600 focus:ring-red-500"
            />
            <span class="text-sm font-medium text-gray-700">Ще има ли safety car?</span>
        </label>

        <InputError :message="form.errors.prediction" />

        <PrimaryButton :disabled="locked || form.processing" :class="{ 'opacity-50': form.processing }">
            {{ prediction ? 'Обнови прогнозата' : 'Запази прогнозата' }}
        </PrimaryButton>
    </form>
</template>
