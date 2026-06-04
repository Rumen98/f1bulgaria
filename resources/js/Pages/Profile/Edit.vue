<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdateF1ProfileForm from './Partials/UpdateF1ProfileForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
    drivers: {
        type: Array,
        default: () => [],
    },
    constructors: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <Head title="Профил" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-black leading-tight text-white">Профил</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div class="rounded-xl border border-zinc-800 border-l-2 border-l-red-600 bg-zinc-900/60 p-4 sm:p-8">
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                        class="max-w-xl"
                    />
                </div>

                <div class="rounded-xl border border-zinc-800 border-l-2 border-l-red-600 bg-zinc-900/60 p-4 sm:p-8">
                    <UpdateF1ProfileForm
                        :drivers="drivers"
                        :constructors="constructors"
                        class="max-w-xl"
                    />
                </div>

                <div class="rounded-xl border border-zinc-800 border-l-2 border-l-red-600 bg-zinc-900/60 p-4 sm:p-8">
                    <UpdatePasswordForm class="max-w-xl" />
                </div>

                <div class="rounded-xl border border-zinc-800 border-l-2 border-l-red-700 bg-zinc-900/60 p-4 sm:p-8">
                    <DeleteUserForm class="max-w-xl" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
