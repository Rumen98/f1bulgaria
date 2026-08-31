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

// Сегментиран бутон: избраното свети в брандовото червено.
const segmentClass = (active) =>
    active
        ? 'rounded-lg border border-red-600 bg-red-600 py-2.5 text-center font-semibold text-white shadow-[0_0_18px_rgba(225,6,0,0.35)] transition duration-200'
        : 'rounded-lg border border-zinc-800 bg-zinc-950 py-2.5 text-center font-semibold text-zinc-400 transition duration-200 hover:border-zinc-600 hover:text-white';

const submit = () => {
    form.post(route('feedback.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <div>
            <span class="block text-sm font-medium text-zinc-300">Как оценяваш Падок?</span>
            <!-- role="group" + aria-pressed, не radiogroup: role="radio" обещава
                 навигация със стрелки, каквато бутоните нямат. -->
            <div class="mt-2 grid grid-cols-5 gap-2" role="group" aria-label="Оценка от 1 (слабо) до 5 (отлично)">
                <button
                    v-for="value in ratings"
                    :key="value"
                    type="button"
                    :aria-pressed="form.rating === value"
                    :class="[segmentClass(form.rating === value), 'font-display text-lg']"
                    @click="form.rating = value"
                >
                    {{ value }}
                </button>
            </div>
            <p class="mt-1.5 flex justify-between text-xs text-zinc-500" aria-hidden="true">
                <span>слабо</span>
                <span>отлично</span>
            </p>
            <InputError class="mt-1" :message="form.errors.rating" />
        </div>

        <div>
            <span class="block text-sm font-medium text-zinc-300">Би ли препоръчал Падок на друг фен?</span>
            <div class="mt-2 grid grid-cols-3 gap-2" role="group" aria-label="Би ли препоръчал Падок">
                <button
                    v-for="option in recommendOptions"
                    :key="option.value"
                    type="button"
                    :aria-pressed="form.would_recommend === option.value"
                    :class="[segmentClass(form.would_recommend === option.value), 'text-sm']"
                    @click="form.would_recommend = option.value"
                >
                    {{ option.label }}
                </button>
            </div>
            <InputError class="mt-1" :message="form.errors.would_recommend" />
        </div>

        <div>
            <label :for="`survey-comment-${source}`" class="block text-sm font-medium text-zinc-300">
                Какво да добавим или променим? <span class="font-normal text-zinc-500">(по избор)</span>
            </label>
            <textarea
                :id="`survey-comment-${source}`"
                v-model="form.comment"
                rows="3"
                maxlength="2000"
                placeholder="Идеи, липсващи неща, дразнещи неща — всичко е добре дошло."
                class="mt-2 block w-full resize-y rounded-lg border-zinc-800 bg-zinc-950 text-sm text-white placeholder-zinc-500 transition focus:border-red-600 focus:ring-1 focus:ring-red-600"
            />
            <InputError class="mt-1" :message="form.errors.comment" />
        </div>

        <button
            type="submit"
            :disabled="form.processing || !form.rating || !form.would_recommend"
            class="w-full rounded-lg bg-red-600 px-5 py-2.5 font-semibold text-white transition duration-200 hover:bg-red-500 disabled:cursor-not-allowed disabled:opacity-40"
        >
            Изпрати
        </button>
    </form>
</template>
