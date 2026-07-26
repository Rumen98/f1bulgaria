<script setup>
import ImportanceDots from '@/Components/News/ImportanceDots.vue';
import NewsCard from '@/Components/News/NewsCard.vue';
import NewsImage from '@/Components/News/NewsImage.vue';
import NewsletterForm from '@/Components/Newsletter/NewsletterForm.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { NEUTRAL_DOT_COLOR } from '@/utils/racing';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    article: { type: Object, required: true },
    related: { type: Array, default: () => [] },
    comments: { type: Array, default: () => [] },
});

const page = usePage();
const user = computed(() => page.props.auth?.user);

const commentForm = useForm({ body: '' });

const submitComment = () => {
    commentForm.post(route('news.comments.store', props.article.slug), {
        preserveScroll: true,
        onSuccess: () => commentForm.reset(),
    });
};

const deleteComment = (id) => {
    if (confirm('Да изтрия ли коментара?')) {
        router.delete(route('comments.destroy', id), { preserveScroll: true });
    }
};

// Разделя текст на параграфи по празни редове.
const toParagraphs = (text) => (text ?? '').split(/\n{2,}/).map((p) => p.trim()).filter(Boolean);

const bodyParagraphs = computed(() => toParagraphs(props.article.full_article));
const analysisParagraphs = computed(() => toParagraphs(props.article.analysis));
</script>

<template>
    <!-- Само title-ът се обновява клиентски при SPA навигация. Всички
         meta/og/canonical тагове се рендерират сървърно (App\Support\Seo),
         защото социалните скрейпъри не изпълняват JavaScript. -->

    <PublicLayout>
        <Link :href="route('news.index')" class="text-sm text-zinc-500 transition hover:text-zinc-300">← Всички новини</Link>

        <div class="mt-3 grid gap-8 lg:grid-cols-3">
            <!-- Статия -->
            <article class="lg:col-span-2">
                <NewsImage v-if="article.image" :image="article.image" :title="article.title" class="mb-6 rounded-2xl border border-zinc-800" />

                <header class="border-b border-zinc-800 pb-6">
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <span v-if="article.classification" class="rounded bg-red-600/90 px-2 py-0.5 font-semibold text-white">
                            {{ article.classification }}
                        </span>
                        <span v-if="article.team" class="inline-flex items-center gap-1 text-zinc-400">
                            <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: article.color ?? NEUTRAL_DOT_COLOR }" />
                            {{ article.team }}
                        </span>
                        <time v-if="article.published_at_iso" :datetime="article.published_at_iso" class="ml-auto text-zinc-500">
                            {{ article.published_at }}
                        </time>
                    </div>

                    <h1 class="mt-3 font-display text-3xl font-black leading-tight text-white sm:text-4xl">{{ article.title }}</h1>

                    <div v-if="article.importance" class="mt-3 flex items-center gap-1 text-xs text-zinc-600">
                        <ImportanceDots :value="article.importance" />
                    </div>
                </header>

                <!-- Пълна статия (ако е генерирана), иначе резюме fallback -->
                <div v-if="bodyParagraphs.length" class="mt-6 space-y-4 text-lg leading-relaxed text-zinc-200">
                    <p v-if="article.summary" class="text-xl font-medium text-zinc-100">{{ article.summary }}</p>
                    <p v-for="(p, i) in bodyParagraphs" :key="i">{{ p }}</p>
                </div>
                <div v-else-if="article.summary" class="mt-6 text-lg leading-relaxed text-zinc-200">
                    {{ article.summary }}
                </div>
                <p v-else class="mt-6 text-zinc-400">Резюмето на български предстои.</p>

                <!-- Ключови факти -->
                <div v-if="article.key_facts && article.key_facts.length" class="mt-8 rounded-2xl border border-zinc-800 bg-zinc-900/40 p-5">
                    <h2 class="mb-3 text-sm font-bold uppercase tracking-wide text-red-500">Ключови факти</h2>
                    <ul class="space-y-2">
                        <li v-for="(fact, i) in article.key_facts" :key="i" class="flex gap-2 text-zinc-300">
                            <span class="mt-1 text-red-500">●</span><span>{{ fact }}</span>
                        </li>
                    </ul>
                </div>

                <!-- Нашият анализ -->
                <div v-if="analysisParagraphs.length" class="mt-8 border-l-2 border-red-600 pl-5">
                    <h2 class="mb-2 font-display text-lg font-bold text-white">Нашият анализ</h2>
                    <div class="space-y-3 leading-relaxed text-zinc-300">
                        <p v-for="(p, i) in analysisParagraphs" :key="i">{{ p }}</p>
                    </div>
                </div>

                <!-- Източник / attribution -->
                <div class="mt-8 rounded-2xl border border-zinc-800 bg-zinc-900/60 p-5">
                    <p class="text-sm text-zinc-400">
                        Резюмето и преводът са изготвени от Падок. Пълната оригинална статия е публикувана от
                        <span v-if="article.source" class="font-semibold text-zinc-200">{{ article.source }}</span>
                        <span v-else>първоизточника</span>.
                    </p>
                    <a
                        v-if="article.external_url"
                        :href="article.external_url"
                        target="_blank"
                        rel="noopener nofollow"
                        class="mt-4 inline-flex items-center gap-2 rounded-lg bg-red-600 px-5 py-2.5 font-semibold text-white transition hover:bg-red-500"
                    >
                        Прочетете оригинала<span v-if="article.source"> на {{ article.source }}</span> →
                    </a>
                </div>

                <!-- Абонамент — тук намерението е най-високо (човекът е дочел статията). -->
                <section class="mt-10 rounded-2xl border border-zinc-800 bg-zinc-900/40 p-6">
                    <h2 class="font-display text-lg font-bold text-white">Новините от Формула 1 всяка седмица</h2>
                    <p class="mt-1 text-sm text-zinc-400">
                        Обобщение на важното от кръга — на български, без спам.
                    </p>
                    <NewsletterForm source="article" class="mt-4" />
                </section>

                <!-- Коментари -->
                <section class="mt-10">
                    <h2 class="mb-4 font-display text-lg font-bold text-white">
                        Коментари <span v-if="comments.length" class="text-zinc-500">({{ comments.length }})</span>
                    </h2>

                    <div v-if="comments.length" class="space-y-3">
                        <div v-for="comment in comments" :key="comment.id" class="rounded-xl border border-zinc-800 bg-zinc-900/40 p-4">
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="font-semibold text-white">{{ comment.author }}</span>
                                <span class="flex items-center gap-3 text-xs text-zinc-500">
                                    {{ comment.created_at_human }}
                                    <button
                                        v-if="comment.can_delete"
                                        type="button"
                                        class="transition hover:text-red-400"
                                        @click="deleteComment(comment.id)"
                                    >
                                        Изтрий
                                    </button>
                                </span>
                            </div>
                            <p class="mt-2 whitespace-pre-line leading-relaxed text-zinc-300">{{ comment.body }}</p>
                        </div>
                    </div>
                    <p v-else class="text-sm text-zinc-500">Все още няма коментари — бъди първият.</p>

                    <form v-if="user" class="mt-5" @submit.prevent="submitComment">
                        <label for="comment-body" class="sr-only">Твоят коментар</label>
                        <textarea
                            id="comment-body"
                            v-model="commentForm.body"
                            rows="3"
                            maxlength="2000"
                            placeholder="Кажи си мнението — уважително и по темата."
                            class="mt-1 block w-full rounded-lg border-zinc-800 bg-zinc-950 text-sm text-white placeholder-zinc-500 transition focus:border-red-600 focus:outline-none focus:ring-1 focus:ring-red-600"
                        />
                        <p v-if="commentForm.errors.body" class="mt-1 text-sm text-red-400">{{ commentForm.errors.body }}</p>
                        <div class="mt-3 flex items-center justify-between gap-3">
                            <span class="text-xs text-zinc-500">Спазвай правилата от Условията за ползване.</span>
                            <button
                                type="submit"
                                :disabled="commentForm.processing"
                                class="rounded-lg bg-red-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-red-500 disabled:opacity-50"
                            >
                                Публикувай
                            </button>
                        </div>
                    </form>
                    <div v-else class="mt-5 rounded-xl border border-zinc-800 bg-zinc-900/40 p-4 text-sm text-zinc-400">
                        <Link :href="route('login')" class="font-semibold text-red-500 transition hover:text-red-400">Влез</Link>
                        или
                        <Link :href="route('register')" class="font-semibold text-red-500 transition hover:text-red-400">се регистрирай</Link>,
                        за да коментираш.
                    </div>
                </section>
            </article>

            <!-- Свързани новини -->
            <aside v-if="related.length" class="lg:col-span-1">
                <h2 class="mb-3 font-display text-lg font-bold text-white">Свързани новини</h2>
                <div class="grid gap-4">
                    <NewsCard v-for="(item, i) in related" :key="i" :item="item" />
                </div>
            </aside>
        </div>
    </PublicLayout>
</template>
