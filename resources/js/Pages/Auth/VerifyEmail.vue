<script setup>
import AuthLayout from '@/Layouts/AuthLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    status: { type: String },
});

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(() => props.status === 'verification-link-sent');
</script>

<template>
    <AuthLayout title="Потвърди имейла си" subtitle="Благодарим, че се присъедини! Потвърди имейла си чрез линка, който ти изпратихме.">
        <Head title="Потвърждение на имейл" />

        <div v-if="verificationLinkSent" class="mb-4 text-sm font-medium text-green-400">
            Нов линк за потвърждение беше изпратен на имейла ти.
        </div>

        <form @submit.prevent="submit">
            <div class="mt-2 flex items-center justify-between">
                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Изпрати отново
                </PrimaryButton>

                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="text-sm text-zinc-400 underline-offset-2 transition hover:text-red-400"
                >
                    Изход
                </Link>
            </div>
        </form>
    </AuthLayout>
</template>
