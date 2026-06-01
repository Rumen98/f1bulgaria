<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    drivers: Array,
    constructors: Array,
});

const user = usePage().props.auth.user;

const form = useForm({
    name: user.name,
    email: user.email,
    bio: user.bio ?? '',
    favorite_driver_id: user.favorite_driver_id ?? null,
    favorite_constructor_id: user.favorite_constructor_id ?? null,
});

const submit = () => form.patch(route('profile.update'), { preserveScroll: true });
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">F1 предпочитания</h2>
            <p class="mt-1 text-sm text-gray-600">
                Любим пилот, отбор и кратко представяне за публичния ти профил.
            </p>
        </header>

        <form class="mt-6 space-y-6" @submit.prevent="submit">
            <div>
                <InputLabel for="bio" value="За мен" />
                <textarea
                    id="bio"
                    v-model="form.bio"
                    rows="3"
                    maxlength="500"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500"
                />
                <InputError class="mt-2" :message="form.errors.bio" />
            </div>

            <div>
                <InputLabel for="favorite_driver_id" value="Любим пилот" />
                <select
                    id="favorite_driver_id"
                    v-model="form.favorite_driver_id"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500"
                >
                    <option :value="null">—</option>
                    <option v-for="d in drivers" :key="d.id" :value="d.id">{{ d.name }}</option>
                </select>
                <InputError class="mt-2" :message="form.errors.favorite_driver_id" />
            </div>

            <div>
                <InputLabel for="favorite_constructor_id" value="Любим отбор" />
                <select
                    id="favorite_constructor_id"
                    v-model="form.favorite_constructor_id"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500"
                >
                    <option :value="null">—</option>
                    <option v-for="c in constructors" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <InputError class="mt-2" :message="form.errors.favorite_constructor_id" />
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing">Запази</PrimaryButton>
                <p v-if="form.recentlySuccessful" class="text-sm text-gray-600">Запазено.</p>
            </div>
        </form>
    </section>
</template>
