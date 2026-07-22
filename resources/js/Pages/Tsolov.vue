<script setup>
import Card from '@/Components/UI/Card.vue';
import StatTile from '@/Components/UI/StatTile.vue';
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

// ЕДИНСТВЕН източник за числата: config/tsolov.php (F2 auto-sync е непълен и е
// изключен в V1). Така банер и класиране никога не се разминават.
const stats = computed(() => props.profile.season_stats ?? null);

// Челен сблъсък с преследвача (Minì): точките му = точки на Цолов минус преднината.
const rivalName = computed(() => props.profile.standings?.[1]?.name ?? 'Minì');
const rivalPoints = computed(() => (stats.value ? stats.value.points - stats.value.championship_lead : null));
const tsolovShare = computed(() => {
    if (!stats.value || rivalPoints.value === null) {
        return 50;
    }
    const total = stats.value.points + rivalPoints.value;
    return total > 0 ? Math.round((stats.value.points / total) * 100) : 50;
});
</script>

<template>
    <Head title="Никола Цолов" />

    <PublicLayout>
        <!-- Hero банер — актуално позициониране като лидер във Формула 2 -->
        <section class="relative overflow-hidden rounded-2xl">
            <picture>
                <source media="(max-width: 767px)" srcset="/images/banners/tsolov/tsolov-750x600.webp" type="image/webp" />
                <source media="(max-width: 767px)" srcset="/images/banners/tsolov/tsolov-750x600.jpg" type="image/jpeg" />
                <source media="(max-width: 1279px)" srcset="/images/banners/tsolov/tsolov-1280x500.webp" type="image/webp" />
                <source media="(max-width: 1279px)" srcset="/images/banners/tsolov/tsolov-1280x500.jpg" type="image/jpeg" />
                <source media="(min-width: 1280px)" srcset="/images/banners/tsolov/tsolov-1600x500.webp" type="image/webp" />
                <img
                    src="/images/banners/tsolov/tsolov-1600x500.jpg"
                    :alt="`${profile.name} — лидер на Формула 2 2026`"
                    class="block h-auto w-full object-cover"
                    loading="eager"
                    width="1600"
                    height="500"
                />
            </picture>

            <!-- Тъмен градиент — скрива остарелия надпис върху банера вляво -->
            <div class="absolute inset-0 bg-gradient-to-r from-black/95 via-black/70 to-transparent" />

            <div class="absolute inset-0 flex items-center">
                <div class="relative max-w-3xl px-6 md:px-12 lg:px-20">
                    <div class="mb-3 inline-flex items-center gap-2 rounded bg-red-600 px-3 py-1 text-xs font-bold uppercase tracking-wide text-white md:text-sm">
                        <span aria-hidden="true">🏆</span> Лидер 2026
                    </div>
                    <!-- Името в трибагреник: стоповете 33/50/67% оформят три ясни ленти бяло/зелено/червено -->
                    <h1 class="font-display text-4xl font-black leading-tight tracking-tight md:text-6xl lg:text-7xl">
                        <span class="bg-gradient-to-b from-white from-33% via-green-500 via-50% to-red-600 to-67% bg-clip-text text-transparent">{{ profile.name }}</span>
                    </h1>
                    <p class="mt-1 font-display text-xl font-black leading-tight tracking-tight text-white md:text-3xl lg:text-4xl">
                        ЛИДЕРЪТ НА <span class="text-red-500">ФОРМУЛА 2</span>
                    </p>
                    <div v-if="stats" class="mt-4 flex flex-wrap items-center gap-2 text-sm font-semibold text-white/95 md:gap-3 md:text-base">
                        <span>{{ stats.points }} точки</span>
                        <span class="text-red-500" aria-hidden="true">·</span>
                        <span>{{ stats.wins }} победи</span>
                        <span class="text-red-500" aria-hidden="true">·</span>
                        <span class="flex items-center gap-1">3 поредни <span class="text-yellow-400" aria-hidden="true">🔥</span></span>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-3 text-sm text-white/80">
                        <span v-if="age">{{ age }} години</span>
                        <span class="text-white/40" aria-hidden="true">·</span>
                        <span>{{ profile.hometown }}</span>
                        <span v-if="profile.current_series" class="text-white/40" aria-hidden="true">·</span>
                        <span v-if="profile.current_series" class="font-semibold text-white">{{ profile.current_series }}</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- Биография -->
        <section class="mx-auto mt-8 max-w-3xl">
            <h2 class="mb-3 font-display text-lg font-bold text-white">Биография</h2>
            <div class="space-y-4 leading-relaxed text-zinc-300">
                <p v-for="(p, i) in profile.bio_bg" :key="i">{{ p }}</p>
            </div>
        </section>

        <!-- Титли (акценти) -->
        <section v-if="profile.titles?.length" class="mx-auto mt-8 max-w-3xl">
            <div class="grid gap-3 sm:grid-cols-2">
                <div v-for="(t, i) in profile.titles" :key="i" class="flex items-center gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4">
                    <span class="text-3xl" aria-hidden="true">🏆</span>
                    <div>
                        <div class="font-display text-lg font-black tabular-nums text-amber-300">{{ t.year }}</div>
                        <div class="text-sm font-semibold text-white">{{ t.title }}</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Кариерна линия -->
        <section v-if="profile.milestones?.length" class="mx-auto mt-8 max-w-3xl">
            <h2 class="mb-4 font-display text-lg font-bold text-white">Кариерен път</h2>
            <div class="space-y-3">
                <Card v-for="(m, i) in profile.milestones" :key="i" class="flex gap-4">
                    <div class="shrink-0 font-display text-xl font-black tabular-nums text-emerald-400">{{ m.year }}</div>
                    <div>
                        <p class="text-zinc-200">{{ m.event }}</p>
                        <p v-if="m.championship" class="text-sm text-amber-400"><span aria-hidden="true">🏆</span> {{ m.championship }}</p>
                    </div>
                </Card>
            </div>
        </section>

        <!-- Сезон 2026 във F2 — класиране + челен сблъсък (източник: config/tsolov.php) -->
        <section v-if="stats" class="mx-auto mt-8 max-w-3xl rounded-2xl border border-emerald-500/30 bg-emerald-500/5 p-6">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="font-display text-lg font-bold text-white">Сезон 2026 във Формула 2 — {{ profile.current_team }}</h2>
                <span class="text-sm text-zinc-500">№{{ profile.car_number }}</span>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <StatTile label="Позиция">P{{ stats.championship_position }}</StatTile>
                <StatTile label="Точки">{{ stats.points }}</StatTile>
                <StatTile label="Победи">{{ stats.wins }}</StatTile>
                <StatTile label="Преднина">+{{ stats.championship_lead }}</StatTile>
            </div>

            <!-- Челен сблъсък с преследвача -->
            <div v-if="rivalPoints !== null" class="mt-5">
                <div class="mb-1.5 flex items-center justify-between text-xs font-semibold uppercase tracking-wide">
                    <span class="text-emerald-400">Цолов · {{ stats.points }} т.</span>
                    <span class="text-zinc-400">{{ rivalName }} · {{ rivalPoints }} т.</span>
                </div>
                <div class="flex h-3 overflow-hidden rounded-full bg-zinc-800 ring-1 ring-inset ring-zinc-700">
                    <div class="bg-emerald-500 transition-[width] duration-700 ease-out" :style="{ width: tsolovShare + '%' }" />
                    <div class="bg-red-600 transition-[width] duration-700 ease-out" :style="{ width: 100 - tsolovShare + '%' }" />
                </div>
                <p class="mt-1.5 text-center text-xs text-zinc-500">Цолов води с {{ stats.championship_lead }} точки</p>
            </div>

            <!-- Мини класиране (топ 5) -->
            <div v-if="profile.standings?.length" class="mt-5 overflow-hidden rounded-xl border border-zinc-800">
                <div
                    v-for="row in profile.standings"
                    :key="row.pos"
                    class="flex items-center gap-3 border-b border-zinc-800/60 px-3 py-2 text-sm last:border-0"
                    :class="row.is_tsolov ? 'bg-emerald-500/10' : ''"
                >
                    <span class="w-5 text-center font-black tabular-nums" :class="row.is_tsolov ? 'text-emerald-400' : 'text-zinc-500'">{{ row.pos }}</span>
                    <span class="min-w-0 flex-1 truncate" :class="row.is_tsolov ? 'font-bold text-white' : 'text-zinc-300'">
                        <span v-if="row.is_tsolov">🇧🇬 </span>{{ row.name }} <span class="text-xs text-zinc-500">· {{ row.team }}</span>
                    </span>
                    <span class="font-bold tabular-nums" :class="row.is_tsolov ? 'text-emerald-400' : 'text-zinc-400'">{{ row.points }}</span>
                </div>
            </div>

            <p class="mt-2 text-right text-xs text-zinc-600">Класиране след R07 Силвърстоун · fiaformula2.com</p>
        </section>

        <!-- Подкрепи -->
        <section class="mx-auto mt-8 max-w-3xl rounded-2xl border border-zinc-800 bg-zinc-900/40 p-6 text-center">
            <h2 class="font-display text-lg font-bold text-white">Подкрепи Цолов</h2>
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
