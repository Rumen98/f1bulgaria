<script setup>
import SurveyForm from '@/Components/Feedback/SurveyForm.vue';
import Card from '@/Components/UI/Card.vue';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

// Скриването се записва сървърно — картата ще се появи пак чак след 6 месеца
// (SurveyPromptService). Затова тук няма локално състояние: и submit, и dismiss
// презареждат споделения survey.shouldPrompt prop и картата изчезва сама.
//
// X-ът се заключва, докато върви заявка: Inertia пуска една визита наведнъж
// и dismiss по време на изпращане би прекъснал POST-а — написаното се губи,
// а вместо „Благодарим" се записва отказ за 6 месеца напред.
const formProcessing = ref(false);
const dismissing = ref(false);

const dismiss = () => {
    if (formProcessing.value || dismissing.value) {
        return;
    }

    router.post(route('feedback.dismiss'), {}, {
        preserveScroll: true,
        onStart: () => (dismissing.value = true),
        onFinish: () => (dismissing.value = false),
    });
};
</script>

<template>
    <Card padding="lg">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="font-display text-lg font-bold text-white">Как ти се струва Падок?</h2>
                <p class="mt-1 text-sm text-zinc-400">
                    Два клика са достатъчни — а отговорите определят какво строим след това.
                </p>
            </div>
            <button
                type="button"
                aria-label="Скрий анкетата"
                :disabled="formProcessing || dismissing"
                class="shrink-0 text-zinc-500 transition hover:text-white disabled:opacity-50"
                @click="dismiss"
            >
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <div class="mt-4 max-w-xl">
            <SurveyForm source="prompt" @processing="formProcessing = $event" />
        </div>
    </Card>
</template>
