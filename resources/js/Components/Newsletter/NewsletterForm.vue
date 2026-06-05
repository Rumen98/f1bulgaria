<script setup>
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    source: { type: String, default: 'footer' },
});

const form = useForm({
    email: '',
    source: props.source,
});

const submit = () => {
    form.post(route('newsletter.subscribe'), {
        preserveScroll: true,
        onSuccess: () => form.reset('email'),
    });
};
</script>

<template>
    <form class="mx-auto flex w-full max-w-md flex-col gap-2 sm:flex-row" @submit.prevent="submit">
        <input
            v-model="form.email"
            type="email"
            required
            placeholder="твоят@имейл.bg"
            class="min-w-0 flex-1 rounded-lg border border-zinc-800 bg-zinc-900 px-4 py-2.5 text-sm text-white placeholder-zinc-600 focus:border-red-600 focus:outline-none"
            :class="{ 'border-red-500': form.errors.email }"
        />
        <button
            type="submit"
            class="shrink-0 rounded-lg bg-red-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-red-500 disabled:opacity-50"
            :disabled="form.processing"
        >
            Абонирай се
        </button>
    </form>

    <p v-if="form.errors.email" class="mt-2 text-xs text-red-400">{{ form.errors.email }}</p>
    <p v-else-if="form.recentlySuccessful" class="mt-2 text-xs text-emerald-400">✓ Благодарим! Записахме имейла ти.</p>
</template>
