<script setup>
import ImportanceDots from '@/Components/News/ImportanceDots.vue';
import NewsImage from '@/Components/News/NewsImage.vue';
import { NEUTRAL_DOT_COLOR } from '@/utils/racing';
import { hasRoute } from '@/utils/routes';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    item: { type: Object, required: true },
});

// Както в NewsCard: картата води към нашата article страница, към източника се
// отива само през малкия линк долу.
const hasInternal = computed(() => Boolean(props.item.slug) && hasRoute('news.show'));

const href = computed(() => (hasInternal.value ? route('news.show', props.item.slug) : props.item.url));
</script>

<template>
    <article
        class="group relative overflow-hidden rounded-2xl border border-red-600/40 bg-zinc-900/60 shadow-xl shadow-red-950/30 transition duration-200 hover:border-red-600/70"
    >
        <!-- Червено сияние — същият визуален език като HeroSection: маркира „водещото" на страницата. -->
        <div
            aria-hidden="true"
            class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_20%_15%,rgba(225,6,0,0.20),transparent_60%)]"
        />

        <div class="relative grid lg:min-h-[340px] lg:grid-cols-5">
            <!-- Визуалът е хоризонтално до текста, за да не изтласква заглавието под сгъвката. -->
            <div class="relative lg:col-span-3">
                <!-- title="" — заглавието стои веднъж, в текстовата колона, иначе се дублира в overlay-а. -->
                <NewsImage :image="item.image" title="" class="lg:absolute lg:inset-0 lg:h-full" />

                <span
                    class="absolute left-4 top-4 z-10 inline-flex items-center gap-2 rounded-full bg-red-600 px-3 py-1.5 text-[11px] font-black uppercase tracking-[0.18em] text-white shadow-lg"
                >
                    <span aria-hidden="true" class="relative flex h-1.5 w-1.5">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-white opacity-75" />
                        <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-white" />
                    </span>
                    Топ новина
                </span>
            </div>

            <div class="flex flex-col justify-center gap-3 p-6 lg:col-span-2 lg:p-8">
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span v-if="item.classification" class="rounded bg-zinc-800 px-1.5 py-0.5 text-zinc-300">{{ item.classification }}</span>
                    <span v-if="item.team" class="inline-flex items-center gap-1 text-zinc-400">
                        <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: item.color ?? NEUTRAL_DOT_COLOR }" />
                        {{ item.team }}
                    </span>
                    <span class="text-zinc-500">{{ item.published_at }}</span>
                </div>

                <h2 class="font-display text-2xl font-black leading-tight text-white transition group-hover:text-red-400 sm:text-3xl">
                    <!-- Stretched link: покрива цялата карта без невалидни вложени котви -->
                    <component
                        :is="hasInternal ? Link : 'a'"
                        :href="href"
                        :target="hasInternal ? undefined : '_blank'"
                        :rel="hasInternal ? undefined : 'noopener'"
                        class="after:absolute after:inset-0"
                    >
                        {{ item.title }}
                    </component>
                </h2>

                <p v-if="item.summary" class="line-clamp-4 text-base leading-relaxed text-zinc-300">
                    {{ item.summary }}
                </p>

                <span class="text-sm font-semibold text-red-500 transition group-hover:text-red-400">Чети повече →</span>

                <!-- Без линк към източника: картата има една задача — да отведе
                     до нашата статия. Атрибуцията живее в дъното на самата статия. -->
                <div class="flex items-center gap-1 text-xs text-zinc-600">
                    <ImportanceDots :value="item.importance ?? 0" />
                </div>
            </div>
        </div>
    </article>
</template>
