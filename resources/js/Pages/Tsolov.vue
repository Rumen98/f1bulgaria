<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    profile: { type: Object, required: true },
    f2: { type: Object, default: null },
});

const age = computed(() => {
    if (!props.profile.birth_date) {
        return null;
    }
    const born = new Date(props.profile.birth_date);
    const now = new Date();
    let years = now.getFullYear() - born.getFullYear();
    const m = now.getMonth() - born.getMonth();
    if (m < 0 || (m === 0 && now.getDate() < born.getDate())) {
        years--;
    }
    return years;
});

const photo = computed(() => props.profile.photos?.[0] ?? null);
</script>

<template>
    <Head title="Никола Цолов" />

    <PublicLayout>
        <!-- Hero с български акцент -->
        <section class="relative overflow-hidden rounded-2xl border border-zinc-800 p-6 sm:p-10"
            style="background: linear-gradient(110deg, rgba(0,150,110,0.25), #0a0a0a 55%, rgba(214,38,18,0.2))">
            <div class="flex flex-col items-center gap-6 text-center sm:flex-row sm:text-left">
                <img v-if="photo" :src="photo" :alt="profile.name" loading="lazy" referrerpolicy="no-referrer" class="h-28 w-28 rounded-full object-cover object-top sm:h-36 sm:w-36" />
                <div v-else class="flex h-28 w-28 items-center justify-center rounded-full bg-zinc-800 text-5xl sm:h-36 sm:w-36">🇧🇬</div>
                <div>
                    <div class="text-xs font-bold uppercase tracking-[0.2em] text-emerald-400">Българската надежда 🇧🇬</div>
                    <h1 class="mt-1 text-3xl font-black sm:text-5xl">{{ profile.name }}</h1>
                    <div class="mt-2 flex flex-wrap items-center justify-center gap-3 text-zinc-300 sm:justify-start">
                        <span v-if="age">{{ age }} години</span>
                        <span class="text-zinc-600">·</span>
                        <span>{{ profile.hometown }}</span>
                        <span v-if="profile.current_series" class="text-zinc-600">·</span>
                        <span v-if="profile.current_series" class="font-semibold text-white">{{ profile.current_series }}</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- Биография -->
        <section class="mx-auto mt-8 max-w-3xl">
            <h2 class="mb-3 text-lg font-bold text-white">Биография</h2>
            <div class="space-y-4 leading-relaxed text-zinc-300">
                <p v-for="(p, i) in profile.bio_bg" :key="i">{{ p }}</p>
            </div>
        </section>

        <!-- Титли (акценти) -->
        <section v-if="profile.titles?.length" class="mx-auto mt-8 max-w-3xl">
            <div class="grid gap-3 sm:grid-cols-2">
                <div v-for="(t, i) in profile.titles" :key="i" class="flex items-center gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4">
                    <span class="text-3xl">🏆</span>
                    <div>
                        <div class="text-lg font-black tabular-nums text-amber-300">{{ t.year }}</div>
                        <div class="text-sm font-semibold text-white">{{ t.title }}</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Кариерна линия -->
        <section v-if="profile.milestones?.length" class="mx-auto mt-8 max-w-3xl">
            <h2 class="mb-4 text-lg font-bold text-white">Кариерен път</h2>
            <div class="space-y-3">
                <div v-for="(m, i) in profile.milestones" :key="i" class="flex gap-4 rounded-xl border border-zinc-800 bg-zinc-900/60 p-4">
                    <div class="shrink-0 text-xl font-black tabular-nums text-emerald-400">{{ m.year }}</div>
                    <div>
                        <p class="text-zinc-200">{{ m.event }}</p>
                        <p v-if="m.championship" class="text-sm text-amber-400">🏆 {{ m.championship }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- В момента в F2 (синхронизирано от Wikipedia) -->
        <section v-if="f2" class="mx-auto mt-8 max-w-3xl rounded-2xl border border-emerald-500/30 bg-emerald-500/5 p-6">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-lg font-bold text-white">В момента във Формула 2 — {{ f2.team }}</h2>
                <span class="text-sm text-zinc-500">Сезон {{ f2.season }}</span>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-xl border border-zinc-800 bg-zinc-950/50 p-3 text-center">
                    <div class="text-2xl font-black tabular-nums text-white">{{ f2.position ?? '—' }}</div>
                    <div class="text-xs uppercase tracking-wide text-zinc-500">Позиция</div>
                </div>
                <div class="rounded-xl border border-zinc-800 bg-zinc-950/50 p-3 text-center">
                    <div class="text-2xl font-black tabular-nums text-white">{{ f2.points }}</div>
                    <div class="text-xs uppercase tracking-wide text-zinc-500">Точки</div>
                </div>
                <div class="rounded-xl border border-zinc-800 bg-zinc-950/50 p-3 text-center">
                    <div class="text-2xl font-black tabular-nums text-white">{{ f2.wins }}</div>
                    <div class="text-xs uppercase tracking-wide text-zinc-500">Победи</div>
                </div>
                <div class="rounded-xl border border-zinc-800 bg-zinc-950/50 p-3 text-center">
                    <div class="text-2xl font-black tabular-nums text-white">{{ f2.podiums }}</div>
                    <div class="text-xs uppercase tracking-wide text-zinc-500">Подиуми</div>
                </div>
            </div>

            <div v-if="f2.latest" class="mt-3 rounded-xl border border-zinc-800 bg-zinc-950/50 p-3 text-sm text-zinc-300">
                Последно: <span class="font-semibold text-white">{{ f2.latest.location }}</span> ({{ f2.latest.session }}) —
                <span class="font-bold">{{ f2.latest.position ? 'P' + f2.latest.position : '—' }}</span>
            </div>

            <Link :href="route('f2.drivers.show', 'nikola-tsolov')" class="mt-4 inline-block text-sm font-medium text-emerald-400 transition hover:text-emerald-300">
                Виж пълните F2 резултати →
            </Link>
        </section>

        <!-- Подкрепи -->
        <section class="mx-auto mt-8 max-w-3xl rounded-2xl border border-zinc-800 bg-zinc-900/40 p-6 text-center">
            <h2 class="text-lg font-bold text-white">Подкрепи Цолов</h2>
            <p class="mt-2 text-zinc-400">Следвай пътя на българския талант към върха на моторспорта.</p>
            <div v-if="profile.social_links?.length" class="mt-4 flex flex-wrap justify-center gap-2">
                <a v-for="(link, i) in profile.social_links" :key="i" :href="link.url" target="_blank" rel="noopener"
                    class="rounded-full border border-zinc-700 px-4 py-2 text-sm font-medium text-zinc-200 transition hover:border-emerald-500 hover:text-white">
                    {{ link.label }}
                </a>
            </div>
        </section>
    </PublicLayout>
</template>
