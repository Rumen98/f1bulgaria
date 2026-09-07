<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { hasRoute } from '@/utils/routes';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    topic: { type: Object, required: true },
    neighbours: { type: Object, default: () => ({ prev: null, next: null }) },
});

const minutes = computed(() => {
    if (props.topic.reading_minutes) {
        return props.topic.reading_minutes;
    }

    const words = [
        props.topic.hero.intro,
        ...props.topic.sections.flatMap((s) => s.paragraphs),
    ]
        .join(' ')
        .split(/\s+/)
        .filter(Boolean).length;

    return Math.max(1, Math.round(words / 180));
});

/**
 * scrollIntoView живее в обработчик на събитие, а не в setup — страницата се
 * рендира и на сървъра, където document го няма.
 */
const scrollTo = (id) => {
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
};
</script>

<template>
    <PublicLayout>
        <article class="mx-auto max-w-3xl">
            <Link :href="route('engineering.index')" class="text-sm text-zinc-500 transition hover:text-zinc-300">
                ← Инженерство
            </Link>

            <header class="relative mt-3 overflow-hidden rounded-2xl border border-zinc-800 bg-gradient-to-br from-red-950/40 via-zinc-950 to-black p-8 sm:p-12">
                <svg viewBox="0 0 400 200" preserveAspectRatio="xMidYMid slice" aria-hidden="true" class="absolute inset-0 h-full w-full opacity-10">
                    <g stroke="#e10600" stroke-width="3" stroke-linecap="round" fill="none">
                        <path d="M-20 60 C 120 60, 180 140, 420 140" />
                        <path d="M-20 100 C 140 100, 200 20, 420 20" />
                    </g>
                </svg>

                <div class="relative">
                    <p v-if="topic.system_label" class="text-xs uppercase tracking-wide text-red-500">{{ topic.system_label }}</p>
                    <h1 class="mt-2 font-display text-3xl font-black sm:text-4xl">{{ topic.hero.title }}</h1>
                    <p class="mt-4 max-w-2xl text-zinc-300 sm:text-lg">{{ topic.hero.intro }}</p>
                    <p class="mt-4 text-xs uppercase tracking-wide text-zinc-500">~{{ minutes }} мин четене</p>
                </div>
            </header>

            <nav aria-label="Съдържание" class="mt-8 rounded-xl border border-zinc-800 bg-zinc-900/40 p-4">
                <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Съдържание</h2>
                <ul class="space-y-1">
                    <li v-for="s in topic.sections" :key="s.id">
                        <button type="button" class="text-left text-sm text-zinc-300 transition hover:text-red-400" @click="scrollTo(s.id)">
                            {{ s.heading }}
                        </button>
                    </li>
                </ul>
            </nav>

            <section v-for="s in topic.sections" :id="s.id" :key="s.id" class="mt-10 scroll-mt-24">
                <h2 class="mb-4 border-l-4 border-red-600 pl-3 font-display text-2xl font-bold text-white">{{ s.heading }}</h2>

                <div class="space-y-4 leading-relaxed text-zinc-300">
                    <p v-for="(p, i) in s.paragraphs" :key="i">{{ p }}</p>
                </div>

                <ul v-if="s.items?.length" class="mt-4 space-y-2 rounded-xl border border-zinc-800 bg-zinc-900/40 p-4">
                    <li v-for="(item, i) in s.items" :key="i" class="flex gap-2.5 text-sm text-zinc-300">
                        <span class="mt-2 h-1 w-1 shrink-0 rounded-full bg-red-600" aria-hidden="true" />
                        <span>{{ item }}</span>
                    </li>
                </ul>
            </section>

            <!-- Термините от речника, употребени в текста. Стоят тук, за да не
                 се налага човек да отваря втора страница по средата на четенето. -->
            <section v-if="topic.glossary?.length" class="mt-12 rounded-2xl border border-zinc-800 bg-zinc-900/40 p-5">
                <h2 class="mb-4 font-display text-lg font-bold text-white">Термините от тази тема</h2>
                <dl class="space-y-3">
                    <div v-for="term in topic.glossary" :key="term.term_en">
                        <dt class="text-sm font-semibold text-zinc-200">
                            {{ term.term_bg }}
                            <span v-if="term.term_en !== term.term_bg" class="font-normal text-zinc-500">· {{ term.term_en }}</span>
                        </dt>
                        <dd class="text-sm leading-relaxed text-zinc-400">{{ term.definition_bg }}</dd>
                    </div>
                </dl>
                <Link
                    v-if="hasRoute('terminology')"
                    :href="route('terminology')"
                    class="mt-4 inline-block text-sm text-red-500 transition hover:text-red-400"
                >
                    Целият речник →
                </Link>
            </section>

            <!-- Източниците не са украса: те са разликата между обяснение и
                 самоуверено твърдение. -->
            <section v-if="topic.sources?.length" class="mt-8">
                <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-zinc-500">Източници</h2>
                <ul class="space-y-1.5">
                    <li v-for="source in topic.sources" :key="source.url" class="text-sm">
                        <a
                            :href="source.url"
                            target="_blank"
                            rel="noopener nofollow"
                            class="text-zinc-400 underline decoration-zinc-700 underline-offset-2 transition hover:text-zinc-200"
                        >
                            {{ source.title }}
                        </a>
                    </li>
                </ul>
            </section>

            <nav v-if="neighbours.prev || neighbours.next" class="mt-10 grid gap-3 sm:grid-cols-2">
                <Link
                    v-if="neighbours.prev"
                    :href="route('engineering.show', neighbours.prev.slug)"
                    class="rounded-xl border border-zinc-800 p-4 transition duration-200 hover:border-red-600/50 hover:bg-zinc-900"
                >
                    <span class="text-xs uppercase tracking-wide text-zinc-500">← Предишна тема</span>
                    <span class="mt-1 block font-medium text-white">{{ neighbours.prev.title }}</span>
                </Link>
                <Link
                    v-if="neighbours.next"
                    :href="route('engineering.show', neighbours.next.slug)"
                    class="rounded-xl border border-zinc-800 p-4 text-right transition duration-200 hover:border-red-600/50 hover:bg-zinc-900 sm:col-start-2"
                >
                    <span class="text-xs uppercase tracking-wide text-zinc-500">Следваща тема →</span>
                    <span class="mt-1 block font-medium text-white">{{ neighbours.next.title }}</span>
                </Link>
            </nav>
        </article>
    </PublicLayout>
</template>
