<script setup>
import EmptyState from '@/Components/UI/EmptyState.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

const props = defineProps({
    questions: { type: Array, default: () => [] },
    total: { type: Number, default: 0 },
    result: { type: Object, default: null }, // != null => режим резултат/ревю
});

// question id -> избрана опция (1..4)
const selected = reactive({});
const submitting = ref(false);

const choose = (qid, n) => {
    selected[qid] = n;
};

const submit = () => {
    submitting.value = true;
    const answers = props.questions.map((q) => ({
        id: q.id,
        choice: selected[q.id] ?? null,
    }));
    router.post(route('quiz.score'), { answers }, {
        preserveScroll: true,
        onFinish: () => {
            submitting.value = false;
        },
    });
};

const restart = () => router.visit(route('quiz')); // нови случайни въпроси
</script>

<template>
    <PublicLayout>
        <h1 class="mb-6 font-display text-2xl font-black sm:text-3xl">
            Формула 1 <span class="text-red-600">Куиз</span>
        </h1>

        <!-- РЕЗУЛТАТ + РЕВЮ -->
        <template v-if="result">
            <div class="mb-6 rounded-xl border border-zinc-800 bg-zinc-900/60 p-6 text-center">
                <p class="font-display text-4xl font-black tabular-nums">
                    {{ result.score }}<span class="text-zinc-500">/{{ result.total }}</span>
                </p>
                <button
                    type="button"
                    class="mt-4 rounded-md bg-red-600 px-4 py-2 font-semibold text-white transition hover:bg-red-500"
                    @click="restart"
                >
                    Нов куиз
                </button>
            </div>

            <div class="space-y-4">
                <div
                    v-for="(item, i) in result.review"
                    :key="item.id"
                    class="rounded-xl border p-4"
                    :class="item.is_correct ? 'border-green-700 bg-green-950/30' : 'border-red-800 bg-red-950/20'"
                >
                    <p class="mb-2 font-semibold">{{ i + 1 }}. {{ item.question }}</p>
                    <ul class="space-y-1 text-sm">
                        <li
                            v-for="(opt, idx) in item.options"
                            :key="idx"
                            :class="{
                                'font-semibold text-green-400': idx + 1 === item.correct_option,
                                'text-red-400 line-through': idx + 1 === item.chosen_option && idx + 1 !== item.correct_option,
                            }"
                        >
                            {{ opt }}
                            <span v-if="idx + 1 === item.correct_option" aria-hidden="true"> ✓</span>
                            <span v-else-if="idx + 1 === item.chosen_option"> ✗ (твоят отговор)</span>
                        </li>
                    </ul>
                </div>
            </div>
        </template>

        <!-- РЕЖИМ КУИЗ -->
        <template v-else>
            <EmptyState v-if="questions.length === 0">
                Все още няма въпроси. Върни се скоро!
            </EmptyState>

            <template v-else>
                <div class="space-y-4">
                    <div
                        v-for="(q, i) in questions"
                        :key="q.id"
                        class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-4"
                    >
                        <p class="mb-3 font-semibold">{{ i + 1 }}. {{ q.question }}</p>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <button
                                v-for="(opt, idx) in q.options"
                                :key="idx"
                                type="button"
                                class="rounded-lg border px-3 py-2 text-left text-sm transition"
                                :class="selected[q.id] === idx + 1
                                    ? 'border-red-600 bg-red-600/20 text-white'
                                    : 'border-zinc-700 text-zinc-300 hover:border-zinc-500'"
                                @click="choose(q.id, idx + 1)"
                            >
                                {{ opt }}
                            </button>
                        </div>
                    </div>
                </div>

                <button
                    type="button"
                    :disabled="submitting"
                    class="mt-6 rounded-md bg-red-600 px-5 py-2.5 font-semibold text-white transition hover:bg-red-500 disabled:opacity-50"
                    @click="submit"
                >
                    Провери резултата
                </button>
            </template>
        </template>
    </PublicLayout>
</template>
