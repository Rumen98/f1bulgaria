<script setup>
import InputError from '@/Components/InputError.vue';
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

const props = defineProps({
    // Откъде е попълнена анкетата — картата-подкана или страницата.
    source: {
        type: String,
        required: true,
        validator: (v) => ['prompt', 'page'].includes(v),
    },
});

// Родителят (картата-подкана) трябва да знае кога върви заявка, за да
// блокира X-а — иначе dismiss би прекъснал изпращането (Inertia пуска
// само една визита наведнъж) и написаното се губи безследно.
const emit = defineEmits(['processing']);

const form = useForm({
    rating: null,
    would_recommend: null,
    comment: '',
    source: props.source,
});

watch(() => form.processing, (value) => emit('processing', value));

const ratings = [1, 2, 3, 4, 5];
// Стойностите отговарят на App\Enums\WouldRecommend.
const recommendOptions = [
    { value: 'yes', label: 'Да' },
    { value: 'maybe', label: 'Може би' },
    { value: 'no', label: 'Не' },
];

const pillClass = (active) =>
    active
        ? 'rounded-md bg-red-600 px-3 py-1.5 text-sm font-medium text-white'
        : 'rounded-md px-3 py-1.5 text-sm font-medium text-zinc-400 transition hover:text-white';

const submit = () => {
    form.post(route('feedback.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <form class="space-y-5" @submit.prevent="submit">
        <div>
            <span class="text-sm font-medium text-zinc-400">Как оценяваш Падок?</span>
            <!-- role="group" + aria-pressed, не radiogroup: role="radio" обещава
                 навигация със стрелки, каквато бутоните нямат. -->
            <div
                class="mt-2 inline-flex rounded-lg border border-zinc-800 bg-zinc-950 p-1"
                role="group"
                aria-label="Оценка от 1 (слабо) до 5 (отлично)"
            >
                <button
                    v-for="value in ratings"
                    :key="value"
                    type="button"
                    :aria-pressed="form.rating === value"
                    :class="pillClass(form.rating === value)"
                    @click="form.rating = value"
                >
                    {{ value }}
                </button>
            </div>
            <p class="mt-1 text-xs text-zinc-500">1 = слабо, 5 = отлично</p>
            <InputError class="mt-1" :message="form.errors.rating" />
        </div>

        <div>
            <span class="text-sm font-medium text-zinc-400">Би ли препоръчал Падок на друг фен?</span>
            <div
                class="mt-2 inline-flex rounded-lg border border-zinc-800 bg-zinc-950 p-1"
                role="group"
                aria-label="Би ли препоръчал Падок"
            >
                <button
                    v-for="option in recommendOptions"
                    :key="option.value"
                    type="button"
                    :aria-pressed="form.would_recommend === option.value"
                    :class="pillClass(form.would_recommend === option.value)"
                    @click="form.would_recommend = option.value"
                >
                    {{ option.label }}
                </button>
            </div>
            <InputError class="mt-1" :message="form.errors.would_recommend" />
        </div>

        <div>
            <label for="survey-comment" class="text-sm font-medium text-zinc-400">
                Какво да добавим или променим? <span class="text-zinc-500">(по избор)</span>
            </label>
            <textarea
                id="survey-comment"
                v-model="form.comment"
                rows="3"
                maxlength="2000"
                placeholder="Идеи, липсващи неща, дразнещи неща — всичко е добре дошло."
                class="mt-1 block w-full rounded-lg border-zinc-800 bg-zinc-950 text-sm text-white placeholder-zinc-500 transition focus:border-red-600 focus:ring-1 focus:ring-red-600"
            />
            <InputError class="mt-1" :message="form.errors.comment" />
        </div>

        <button
            type="submit"
            :disabled="form.processing || !form.rating || !form.would_recommend"
            class="w-full rounded-lg bg-red-600 px-5 py-2.5 font-semibold text-white transition duration-200 hover:bg-red-500 disabled:opacity-50"
        >
            Изпрати
        </button>
    </form>
</template>
