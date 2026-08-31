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
        <div class="relative">
            <button
                type="button"
                aria-label="Скрий анкетата"
                :disabled="formProcessing || dismissing"
                class="absolute -right-1 -top-1 rounded-md p-1 text-zinc-500 transition hover:bg-zinc-800/60 hover:text-white disabled:opacity-50"
                @click="dismiss"
            >
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>

            <!-- На десктоп текстът застава вляво, формата вдясно — картата е на
                 цялата ширина на контейнера и една тясна колона я оставя куха. -->
            <div class="lg:flex lg:items-start lg:gap-12">
                <div class="mb-6 pr-8 lg:mb-0 lg:w-72 lg:shrink-0">
                    <p class="text-xs font-bold uppercase tracking-widest text-zinc-500">Обратна връзка</p>
                    <h2 class="mt-1.5 font-display text-xl font-black sm:text-2xl">
                        Как ти се струва <span class="text-red-600">Падок</span>?
                    </h2>
                    <p class="mt-2 text-sm leading-relaxed text-zinc-400">
                        Два клика са достатъчни — а отговорите определят какво строим след това.
                    </p>
                    <p class="mt-4 hidden text-xs text-zinc-500 lg:block">
                        Четем всеки отговор лично. Без анкети по имейл, без напомняния.
                    </p>
                </div>
                <div class="min-w-0 flex-1 lg:max-w-xl">
                    <SurveyForm source="prompt" @processing="formProcessing = $event" />
                </div>
            </div>
        </div>
    </Card>
</template>
