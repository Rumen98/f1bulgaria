<script setup>
import QuizLeaderboard from '@/Components/Quiz/QuizLeaderboard.vue';
import QuizProgress from '@/Components/Quiz/QuizProgress.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import TableShell from '@/Components/UI/TableShell.vue';
import Trophy from '@/Components/UI/Trophy.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { podiumClass } from '@/utils/racing';
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    questions: { type: Array, default: () => [] },
    result: { type: Object, default: null }, // != null => режим резултат/ревю
    stats: { type: Object, default: () => ({ points: 0, available: 0, attempts: 0, best_score: null, best_total: null }) },
    leaderboard: { type: Array, default: () => [] },
});

const currentUser = computed(() => usePage().props.auth?.user ?? null);

// ── Режим куиз ────────────────────────────────────────────────────────────
const selected = reactive({});
const submitting = ref(false);
const choose = (qid, n) => (selected[qid] = n);
const answeredCount = computed(() => props.questions.filter((q) => selected[q.id]).length);
const allAnswered = computed(() => props.questions.length > 0 && answeredCount.value === props.questions.length);

const submit = () => {
    submitting.value = true;
    const answers = props.questions.map((q) => ({ id: q.id, choice: selected[q.id] ?? null }));
    router.post(route('quiz.score'), { answers }, { preserveScroll: false, onFinish: () => (submitting.value = false) });
};
const restart = () => router.visit(route('quiz'));

// ── Режим резултат: превръщаме резултата в „класация от Гран При" ───────────
// Позиция P1..P20 по процент верни. Прагове вместо линейна формула: линейната
// правеше P3 недостижим при 10 въпроса. Точки по F1 схемата.
const F1_POINTS = [25, 18, 15, 12, 10, 8, 6, 4, 2, 1];
const pct = computed(() => (props.result && props.result.total ? props.result.score / props.result.total : 0));
const position = computed(() => {
    if (pct.value === 1) {
        return 1;
    }
    if (pct.value >= 0.9) {
        return 2;
    }
    if (pct.value >= 0.8) {
        return 3;
    }
    return Math.min(20, 4 + Math.round(((0.8 - pct.value) / 0.8) * 16));
});
const points = computed(() => F1_POINTS[position.value - 1] ?? 0);
const isPerfect = computed(() => props.result && props.result.total > 0 && props.result.score === props.result.total);
const wrongCount = computed(() => (props.result ? props.result.total - props.result.score : 0));

const tier = computed(() => {
    const p = position.value;
    if (p === 1) {
        return { label: isPerfect.value ? 'GRAND CHELEM' : 'ПОБЕДА', sub: 'Стъпи на върха на подиума', ring: 'ring-amber-400/50', glow: 'from-amber-500/20', text: 'text-amber-300', emoji: '🏆' };
    }
    if (p <= 3) {
        return { label: 'ПОДИУМ', sub: 'Място на подиума', ring: 'ring-zinc-300/40', glow: 'from-zinc-400/15', text: 'text-zinc-200', emoji: '🍾' };
    }
    if (p <= 10) {
        return { label: 'В ТОЧКИТЕ', sub: 'Солиден резултат', ring: 'ring-emerald-500/40', glow: 'from-emerald-500/15', text: 'text-emerald-300', emoji: '✅' };
    }
    return { label: 'ИЗВЪН ТОЧКИТЕ', sub: 'Има какво да наваксаш до следващия кръг', ring: 'ring-zinc-700', glow: 'from-zinc-700/20', text: 'text-zinc-400', emoji: '🏁' };
});

// Подиумни стъпала (P2 · P1 · P3) — „ТИ" се показва на своето, ако е топ 3.
const podiumSteps = [
    { pos: 2, h: 'h-20', color: 'bg-zinc-500/30 border-zinc-400/50' },
    { pos: 1, h: 'h-28', color: 'bg-amber-500/25 border-amber-400/60' },
    { pos: 3, h: 'h-14', color: 'bg-orange-600/25 border-orange-500/50' },
];

// Анимации + count-up на точките. Наблюдаваме result вместо onMounted:
// router.post към същата страница преизползва компонента и onMounted не се
// вика повторно — точките оставаха 0 завинаги.
const revealed = ref(false);
const displayPoints = ref(0);
let countUpToken = 0; // прекъсва застоял rAF цикъл при нов резултат

watch(
    () => props.result,
    (result) => {
        if (typeof window === 'undefined') {
            return; // SSR: няма rAF/matchMedia; клиентът стартира анимацията сам
        }
        const token = ++countUpToken;
        revealed.value = false;
        displayPoints.value = 0;
        requestAnimationFrame(() => (revealed.value = true));
        if (!result) {
            return;
        }
        const target = points.value;
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            displayPoints.value = target;
            return;
        }
        // Стартът е timestamp-ът на първия кадър, не performance.now():
        // скрит таб отлага rAF и иначе анимацията се прескача изцяло.
        let start = null;
        const step = (t) => {
            if (token !== countUpToken) {
                return;
            }
            if (start === null) {
                start = t;
            }
            const k = Math.min(1, (t - start) / 900);
            displayPoints.value = Math.round(target * (1 - Math.pow(1 - k, 3)));
            if (k < 1) {
                requestAnimationFrame(step);
            }
        };
        requestAnimationFrame(step);
    },
    { immediate: true },
);
</script>

<template>
    <PublicLayout>
        <!-- ═══════════════ РЕЗУЛТАТ = ФИНАЛНА КЛАСАЦИЯ ═══════════════ -->
        <template v-if="result">
            <h1 class="sr-only">Куизът на Падок — резултат</h1>

            <!-- Hero: шахматно знаме + позиция + точки -->
            <section
                class="relative overflow-hidden rounded-2xl border border-zinc-800 bg-gradient-to-b to-black p-6 text-center transition-all duration-700 sm:p-8"
                :class="[tier.glow, revealed ? 'opacity-100 translate-y-0 scale-100' : 'opacity-0 translate-y-3 scale-95']"
            >
                <div class="flag pointer-events-none absolute inset-x-0 top-0 h-2" />
                <p class="text-xs font-black uppercase tracking-[0.3em] text-zinc-500">Финална класация</p>

                <div class="mt-4 flex items-end justify-center gap-3">
                    <Trophy v-if="position <= 3" :place="position" class="h-14 w-14 drop-shadow-lg sm:h-16 sm:w-16" />
                    <span v-else class="text-3xl">{{ tier.emoji }}</span>
                    <span class="font-display text-7xl font-black leading-none tabular-nums sm:text-8xl" :class="podiumClass(position) || 'text-white'">
                        P{{ position }}
                    </span>
                </div>

                <div class="mt-3 inline-flex items-center rounded-full px-3 py-1 text-sm font-black uppercase tracking-wider ring-1" :class="[tier.ring, tier.text]">
                    {{ tier.label }}
                </div>
                <p class="mt-2 text-sm text-zinc-400">{{ tier.sub }}</p>

                <!-- Метрики като табло -->
                <div class="mx-auto mt-6 grid max-w-md grid-cols-3 gap-3">
                    <div class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-3">
                        <div class="font-display text-2xl font-black tabular-nums text-white">{{ result.score }}<span class="text-base text-zinc-500">/{{ result.total }}</span></div>
                        <div class="mt-0.5 text-[11px] uppercase tracking-wide text-zinc-500">Верни</div>
                    </div>
                    <div class="rounded-xl border border-red-900/50 bg-red-950/20 p-3">
                        <div class="font-display text-2xl font-black tabular-nums text-amber-300">{{ displayPoints }}</div>
                        <div class="mt-0.5 text-[11px] uppercase tracking-wide text-zinc-500">Точки</div>
                    </div>
                    <div class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-3">
                        <div class="font-display text-2xl font-black tabular-nums" :class="wrongCount ? 'text-zinc-300' : 'text-emerald-400'">{{ Math.round(pct * 100) }}%</div>
                        <div class="mt-0.5 text-[11px] uppercase tracking-wide text-zinc-500">Точност</div>
                    </div>
                </div>

                <p v-if="isPerfect" class="mt-4 inline-block rounded-md bg-purple-500/15 px-3 py-1 text-xs font-bold uppercase tracking-wide text-purple-300">
                    🟪 Пълен резултат · най-бърза обиколка
                </p>
            </section>

            <!-- Подиум (само за топ 3) -->
            <section
                v-if="position <= 3"
                class="mt-6 flex items-end justify-center gap-2 transition-all delay-200 duration-700 sm:gap-4"
                :class="revealed ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
            >
                <div v-for="step in podiumSteps" :key="step.pos" class="flex w-20 flex-col items-center sm:w-24">
                    <Trophy :place="step.pos" class="mb-1 h-10 w-10 sm:h-12 sm:w-12" :class="step.pos === position ? 'motion-safe:animate-bounce' : 'opacity-60'" />
                    <div class="flex w-full items-start justify-center rounded-t-lg border-t border-x pt-2 font-display text-xl font-black tabular-nums" :class="[step.h, step.color, podiumClass(step.pos)]">
                        {{ step.pos }}
                    </div>
                    <div v-if="step.pos === position" class="w-full bg-red-600 py-1 text-center text-[11px] font-black uppercase tracking-wide text-white">ТИ</div>
                </div>
            </section>

            <!-- Класация (всеки въпрос = ред, като резултати от състезание) -->
            <h2 class="mb-3 mt-8 font-display text-lg font-black uppercase tracking-wide text-white">Класация по въпроси</h2>
            <TableShell>
                <table class="w-full text-sm">
                    <thead class="bg-zinc-900/80 text-xs uppercase tracking-wide text-zinc-500">
                        <tr>
                            <th class="w-10 px-2 py-2 text-left sm:px-3">Поз</th>
                            <th class="px-2 py-2 text-left sm:px-3">Въпрос</th>
                            <th class="hidden px-3 py-2 text-left md:table-cell">Твоят отговор</th>
                            <th class="px-2 py-2 text-center sm:px-3">Резултат</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-800/60">
                        <tr
                            v-for="(item, i) in result.review"
                            :key="item.id"
                            class="transition-all duration-500"
                            :class="[
                                item.is_correct ? 'bg-emerald-950/20' : 'bg-red-950/15',
                                revealed ? 'opacity-100 translate-x-0' : 'opacity-0 -translate-x-2',
                            ]"
                            :style="{ transitionDelay: 150 + i * 45 + 'ms' }"
                        >
                            <td class="px-2 py-2.5 font-black tabular-nums text-zinc-500 sm:px-3">{{ i + 1 }}</td>
                            <td class="px-2 py-2.5 sm:px-3">
                                <div class="font-medium text-zinc-200">{{ item.question }}</div>
                                <div v-if="!item.is_correct" class="mt-0.5 text-xs text-emerald-400">Вярно: {{ item.options[item.correct_option - 1] }}</div>
                            </td>
                            <td class="hidden px-3 py-2.5 md:table-cell" :class="item.is_correct ? 'text-emerald-300' : 'text-red-300 line-through'">
                                {{ item.chosen_option ? item.options[item.chosen_option - 1] : '— пропуснат' }}
                            </td>
                            <td class="px-2 py-2.5 text-center sm:px-3">
                                <span v-if="item.is_correct" class="inline-flex items-center rounded-full bg-emerald-500/15 px-2 py-0.5 text-xs font-bold text-emerald-400">✓</span>
                                <span v-else class="inline-flex items-center rounded-full bg-red-500/15 px-2 py-0.5 text-xs font-bold text-red-400">✗</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </TableShell>

            <div class="mt-6 flex flex-wrap justify-center gap-3">
                <button type="button" class="rounded-lg bg-red-600 px-6 py-2.5 font-bold text-white shadow-lg shadow-red-600/20 transition hover:bg-red-500" @click="restart">
                    🏁 Нов кръг
                </button>
            </div>

            <!-- Постоянният прогрес живее ПОД кръга: показва, че резултатът е
                 оставил следа, вместо да изчезне с напускането на страницата. -->
            <div class="mt-8">
                <QuizProgress :stats="stats" :new-points="result.new_points ?? 0" :authenticated="!!currentUser" />
            </div>

            <div class="mt-8">
                <QuizLeaderboard :rows="leaderboard" :current-user-id="currentUser?.id ?? null" />
            </div>
        </template>

        <!-- ═══════════════ РЕЖИМ КУИЗ ═══════════════ -->
        <template v-else>
            <div class="mb-6 flex items-center gap-2.5">
                <span class="flag-chip h-6 w-6 rounded" />
                <h1 class="font-display text-2xl font-black sm:text-3xl">Куизът на Падок<span class="text-red-600">.</span></h1>
            </div>

            <div class="mb-6">
                <QuizProgress :stats="stats" :authenticated="!!currentUser" />
            </div>

            <EmptyState v-if="questions.length === 0">Все още няма въпроси. Върни се скоро!</EmptyState>

            <template v-else>
                <!-- Прогрес (стартова решетка) -->
                <div class="sticky top-16 z-10 mb-5 rounded-xl border border-zinc-800 bg-black/80 p-3 backdrop-blur">
                    <div class="mb-1.5 flex items-center justify-between text-xs font-semibold uppercase tracking-wide text-zinc-400">
                        <span>Отговорени</span>
                        <span class="tabular-nums" :class="allAnswered ? 'text-emerald-400' : 'text-zinc-300'">{{ answeredCount }}/{{ questions.length }}</span>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-zinc-800">
                        <div class="h-full bg-gradient-to-r from-red-600 to-red-400 transition-all duration-300" :style="{ width: (questions.length ? answeredCount / questions.length * 100 : 0) + '%' }" />
                    </div>
                </div>

                <div class="space-y-4">
                    <div
                        v-for="(q, i) in questions"
                        :key="q.id"
                        class="rounded-xl border bg-zinc-900/60 p-4 transition"
                        :class="selected[q.id] ? 'border-red-900/60' : 'border-zinc-800 hover:border-zinc-700'"
                    >
                        <div class="mb-3 flex items-start gap-3">
                            <span
                                class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md font-display text-sm font-black tabular-nums transition-colors"
                                :class="selected[q.id] ? 'bg-red-600 text-white' : 'bg-zinc-800 text-zinc-400'"
                            >{{ i + 1 }}</span>
                            <p :id="`quiz-q-${q.id}`" class="pt-0.5 font-semibold text-white">{{ q.question }}</p>
                        </div>
                        <div class="grid gap-2 sm:grid-cols-2" role="group" :aria-labelledby="`quiz-q-${q.id}`">
                            <button
                                v-for="(opt, idx) in q.options"
                                :key="idx"
                                type="button"
                                class="flex items-center gap-2 rounded-lg border px-3 py-2 text-left text-sm transition"
                                :class="selected[q.id] === idx + 1
                                    ? 'border-red-600 bg-red-600/20 text-white ring-1 ring-red-600/40'
                                    : 'border-zinc-700 text-zinc-300 hover:border-zinc-500 hover:bg-zinc-800/40'"
                                :aria-pressed="selected[q.id] === idx + 1"
                                @click="choose(q.id, idx + 1)"
                            >
                                <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border text-[10px] font-black" :class="selected[q.id] === idx + 1 ? 'border-red-500 bg-red-600 text-white' : 'border-zinc-600 text-zinc-500'">
                                    {{ ['A', 'B', 'C', 'D'][idx] }}
                                </span>
                                {{ opt }}
                            </button>
                        </div>
                    </div>
                </div>

                <div class="sticky bottom-2 mt-6">
                    <button
                        type="button"
                        :disabled="submitting"
                        class="w-full rounded-lg bg-red-600 px-5 py-3 font-bold text-white shadow-lg shadow-red-600/20 transition hover:bg-red-500 disabled:opacity-50"
                        @click="submit"
                    >
                        <span v-if="submitting">Пресичане на финала…</span>
                        <span v-else-if="!allAnswered">🏁 Финиширай ({{ answeredCount }}/{{ questions.length }})</span>
                        <span v-else>🏁 Провери резултата</span>
                    </button>
                </div>
            </template>

            <div class="mt-10">
                <QuizLeaderboard :rows="leaderboard" :current-user-id="currentUser?.id ?? null" />
            </div>
        </template>
    </PublicLayout>
</template>

<style scoped>
/* Шахматно знаме (лента + чип) */
.flag,
.flag-chip {
    background-image:
        repeating-conic-gradient(#fff 0deg 90deg, #18181b 90deg 180deg);
    background-size: 16px 16px;
}
.flag-chip {
    background-size: 6px 6px;
}
</style>
