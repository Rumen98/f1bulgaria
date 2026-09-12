<script setup>
import InputError from '@/Components/InputError.vue';
import axios from 'axios';
import { reactive, ref, useId, watch } from 'vue';

const props = defineProps({
    sessionId: { type: Number, default: null },
    initialOpen: { type: Boolean, default: false },
});

const emit = defineEmits(['submitted', 'dismiss']);
const formId = useId();
const expanded = ref(props.initialOpen);
const processing = ref(false);
const submitted = ref(false);
const errorMessage = ref('');
const errors = ref({});
const answers = reactive({
    rating: null,
    controls_rating: null,
    performance_rating: null,
    difficulty: null,
    reason: null,
    would_play_again: null,
    comment: '',
});

const reasons = [
    { value: 'controls', label: 'Трудно ми беше да управлявам' },
    { value: 'performance', label: 'Насичаше / графиката беше лоша' },
    { value: 'unclear_goal', label: 'Не разбрах целта или правилата' },
    { value: 'no_time', label: 'Нямах достатъчно време' },
    { value: 'bug', label: 'Попаднах на технически проблем' },
    { value: 'just_trying', label: 'Просто разглеждах и пробвах' },
    { value: 'other', label: 'Друга причина' },
];

const detailRatings = [
    { field: 'controls_rating', label: 'Управление', low: 'Много трудно', high: 'Много удобно' },
    { field: 'performance_rating', label: 'Плавност и графика', low: 'Много зле', high: 'Много добре' },
];

watch(() => props.initialOpen, (value) => {
    if (value) expanded.value = true;
});

const fieldError = (field) => errors.value[field]?.[0] ?? '';

async function submit() {
    if (processing.value) return;

    processing.value = true;
    errorMessage.value = '';
    errors.value = {};
    const sessionId = props.sessionId;

    try {
        await axios.post(route('game.feedback.store'), {
            ...answers,
            session_id: sessionId,
        });
        submitted.value = true;
        emit('submitted', { sessionId });
    } catch (error) {
        const status = error.response?.status;
        if (status === 422) {
            errors.value = error.response.data.errors ?? {};
            errorMessage.value = fieldError('session_id') || 'Провери отбелязаните полета и опитай отново.';
        } else if (status === 401 || status === 419) {
            errorMessage.value = 'Входът ти е изтекъл. Отвори сайта в нов раздел, влез отново и опитай пак тук.';
        } else if (status === 429) {
            errorMessage.value = 'Изчакай около минута, преди да изпратиш отново.';
        } else if (status === 403) {
            errorMessage.value = 'Мнението за играта е достъпно за играли потребители с активен акаунт.';
        } else {
            errorMessage.value = 'Не успяхме да изпратим мнението. Написаното е запазено тук — опитай отново.';
        }
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <section class="rounded-2xl border border-zinc-800 bg-zinc-900/70 p-5 sm:p-6" :aria-labelledby="`${formId}-title`">
        <div v-if="submitted" class="flex items-start justify-between gap-4" role="status">
            <div>
                <h2 :id="`${formId}-title`" class="font-display text-lg font-bold text-white">Благодарим за мнението!</h2>
                <p class="mt-1 text-sm leading-relaxed text-zinc-400">То ще ни помогне да подобрим играта и първото каране за следващите играчи.</p>
            </div>
            <button type="button" class="shrink-0 rounded-lg px-3 py-2 text-sm text-zinc-400 hover:bg-zinc-800 hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-red-500" @click="emit('dismiss')">Затвори</button>
        </div>

        <template v-else>
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-red-400">Само за играта</p>
                    <h2 :id="`${formId}-title`" class="mt-1 font-display text-lg font-bold text-white sm:text-xl">Как беше зад волана?</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-zinc-400">Дори да си карал само за кратко, кажи какво ти хареса или те затрудни. Само общата оценка е задължителна.</p>
                </div>
                <button type="button" :disabled="processing" class="shrink-0 rounded-lg px-2 py-2 text-xs text-zinc-400 hover:bg-zinc-800 hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-red-500 disabled:opacity-50" @click="emit('dismiss')">Не сега</button>
            </div>

            <button v-if="!expanded" type="button" class="mt-4 rounded-xl border border-red-500/40 bg-red-500/10 px-4 py-2.5 text-sm font-semibold text-red-300 transition hover:bg-red-500/20 focus-visible:outline focus-visible:outline-2 focus-visible:outline-red-500" :aria-expanded="expanded" :aria-controls="`${formId}-form`" @click="expanded = true">Сподели мнение за играта</button>

            <form v-if="expanded" :id="`${formId}-form`" class="mt-5" :aria-busy="processing" @submit.prevent="submit">
                <fieldset :disabled="processing" class="space-y-5 disabled:opacity-60">
                    <fieldset>
                        <legend class="text-sm font-semibold text-zinc-200">Как оценяваш играта? <span class="text-red-400" aria-hidden="true">*</span></legend>
                        <div class="mt-2 grid max-w-sm grid-cols-5 gap-2">
                            <label v-for="value in 5" :key="value" class="cursor-pointer">
                                <input v-model="answers.rating" type="radio" :name="`${formId}-rating`" :value="value" required class="peer sr-only" :aria-label="`${value} от 5`" :aria-invalid="Boolean(fieldError('rating'))" :aria-describedby="`${formId}-rating-scale ${formId}-rating-error`" />
                                <span class="flex min-h-11 items-center justify-center rounded-lg border border-zinc-700 bg-zinc-950 font-display text-lg font-bold text-zinc-300 transition hover:border-zinc-500 peer-checked:border-red-500 peer-checked:bg-red-600 peer-checked:text-white peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-red-400">{{ value }}</span>
                            </label>
                        </div>
                        <p :id="`${formId}-rating-scale`" class="mt-1.5 flex max-w-sm justify-between text-xs text-zinc-500"><span>1 — слабо</span><span>5 — отлично</span></p>
                        <InputError :id="`${formId}-rating-error`" class="mt-1" :message="fieldError('rating')" />
                    </fieldset>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label :for="`${formId}-reason`" class="text-sm font-medium text-zinc-300">Ако спря по-рано, каква беше причината?</label>
                            <select :id="`${formId}-reason`" v-model="answers.reason" :aria-invalid="Boolean(fieldError('reason'))" :aria-describedby="`${formId}-reason-error`" class="mt-2 block w-full rounded-lg border-zinc-700 bg-zinc-950 text-sm text-zinc-200 focus:border-red-500 focus:ring-red-500">
                                <option :value="null">По избор / не се отнася за мен</option>
                                <option v-for="reason in reasons" :key="reason.value" :value="reason.value">{{ reason.label }}</option>
                            </select>
                            <InputError :id="`${formId}-reason-error`" class="mt-1" :message="fieldError('reason')" />
                        </div>
                        <div>
                            <label :for="`${formId}-difficulty`" class="text-sm font-medium text-zinc-300">Как ти се стори трудността?</label>
                            <select :id="`${formId}-difficulty`" v-model="answers.difficulty" :aria-invalid="Boolean(fieldError('difficulty'))" :aria-describedby="`${formId}-difficulty-error`" class="mt-2 block w-full rounded-lg border-zinc-700 bg-zinc-950 text-sm text-zinc-200 focus:border-red-500 focus:ring-red-500">
                                <option :value="null">По избор</option>
                                <option value="easy">Твърде лесна</option>
                                <option value="right">Точно както трябва</option>
                                <option value="hard">Твърде трудна</option>
                            </select>
                            <InputError :id="`${formId}-difficulty-error`" class="mt-1" :message="fieldError('difficulty')" />
                        </div>
                        <div v-for="detail in detailRatings" :key="detail.field">
                            <label :for="`${formId}-${detail.field}`" class="text-sm font-medium text-zinc-300">{{ detail.label }}</label>
                            <select :id="`${formId}-${detail.field}`" v-model="answers[detail.field]" :aria-invalid="Boolean(fieldError(detail.field))" :aria-describedby="`${formId}-${detail.field}-error`" class="mt-2 block w-full rounded-lg border-zinc-700 bg-zinc-950 text-sm text-zinc-200 focus:border-red-500 focus:ring-red-500">
                                <option :value="null">По избор</option>
                                <option v-for="value in 5" :key="value" :value="value">{{ value }}{{ value === 1 ? ` — ${detail.low}` : value === 5 ? ` — ${detail.high}` : '' }}</option>
                            </select>
                            <InputError :id="`${formId}-${detail.field}-error`" class="mt-1" :message="fieldError(detail.field)" />
                        </div>
                    </div>

                    <div>
                        <label :for="`${formId}-comment`" class="text-sm font-medium text-zinc-300">Какво да подобрим в играта?</label>
                        <textarea :id="`${formId}-comment`" v-model="answers.comment" rows="3" maxlength="2000" class="mt-2 block w-full rounded-lg border-zinc-700 bg-zinc-950 text-sm text-zinc-200 placeholder:text-zinc-600 focus:border-red-500 focus:ring-red-500" placeholder="Например: на телефона ми е трудно да завивам, не разбрах кога започва обиколката…" :aria-invalid="Boolean(fieldError('comment'))" :aria-describedby="`${formId}-comment-help ${formId}-comment-error`" />
                        <p :id="`${formId}-comment-help`" class="mt-1 text-xs text-zinc-500">По избор · до 2000 знака. При технически проблем можеш да посочиш телефона или браузъра.</p>
                        <InputError :id="`${formId}-comment-error`" class="mt-1" :message="fieldError('comment')" />
                    </div>

                    <div>
                        <label :for="`${formId}-again`" class="text-sm font-medium text-zinc-300">Би ли играл отново?</label>
                        <select :id="`${formId}-again`" v-model="answers.would_play_again" :aria-invalid="Boolean(fieldError('would_play_again'))" :aria-describedby="`${formId}-again-error`" class="mt-2 block w-full rounded-lg border-zinc-700 bg-zinc-950 text-sm text-zinc-200 focus:border-red-500 focus:ring-red-500 sm:max-w-xs">
                            <option :value="null">По избор</option>
                            <option value="yes">Да</option>
                            <option value="maybe">Може би</option>
                            <option value="no">Не</option>
                        </select>
                        <InputError :id="`${formId}-again-error`" class="mt-1" :message="fieldError('would_play_again')" />
                    </div>

                    <p v-if="errorMessage" role="alert" class="rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2 text-sm text-red-300">{{ errorMessage }}</p>
                    <div class="flex flex-wrap items-center gap-3 border-t border-zinc-800 pt-4">
                        <button type="submit" :disabled="processing" class="rounded-xl bg-red-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-red-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-400 disabled:cursor-wait disabled:opacity-60">{{ processing ? 'Изпращане…' : 'Изпрати мнение за играта' }}</button>
                        <p class="max-w-sm text-xs leading-relaxed text-zinc-500">Мнението е до екипа на Падок, свързано с акаунта ти. Не се публикува в класацията.</p>
                    </div>
                </fieldset>
            </form>
        </template>
    </section>
</template>
