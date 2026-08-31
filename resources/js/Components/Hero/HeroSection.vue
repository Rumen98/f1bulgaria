<script setup>
import Card from '@/Components/UI/Card.vue';
import { useCountdown } from '@/composables/useCountdown';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    hero: { type: Object, required: true },
});

// „Вижте детайли" не казва какво следва и е на „вие", а останалият сайт е на
// „ти". Текстът се сменя според това какво липсва на човека.
const currentUser = computed(() => usePage().props.auth?.user ?? null);
const ctaLabel = computed(() => {
    // Наличен победител значи, че кръгът е приключил — тогава на човека му
    // трябват резултатите, не форма за прогноза.
    if (props.hero?.winner) {
        return 'Виж резултатите →';
    }

    return currentUser.value ? 'Подай прогноза →' : 'Прогнозирай подиума →';
});

// Raw SVG-та на пистите (Vite ги inline-ва като стрингове).
const svgs = import.meta.glob('../../../svg/circuits/*.svg', {
    query: '?raw',
    import: 'default',
    eager: true,
});

const trackSvg = computed(() => {
    const slug = props.hero?.circuit_slug;
    if (!slug) {
        return null;
    }
    const key = Object.keys(svgs).find((path) => path.endsWith(`/${slug}.svg`));
    return key ? svgs[key] : null;
});

const sessions = computed(() => props.hero?.sessions ?? []);
const winner = computed(() => props.hero?.winner ?? null);

const kicker = computed(() => ({
    active: 'Уикендът тече',
    upcoming: 'Следващо състезание',
    off_season: 'Извън сезона',
})[props.hero?.state] ?? '');

const { days, hours, minutes, seconds, finished } = useCountdown(() => props.hero?.countdown_to ?? null);

const plural = (n, one, many) => (n === 1 ? one : many);

const countdownText = computed(() => {
    if (!props.hero?.countdown_to) {
        return '—';
    }
    if (finished.value) {
        return 'В момента';
    }
    const d = days.value;
    const h = hours.value;
    const m = minutes.value;
    if (d > 0) {
        return `${d} ${plural(d, 'ден', 'дни')} ${h} ${plural(h, 'час', 'часа')} ${m} мин`;
    }
    if (h > 0) {
        return `${h} ${plural(h, 'час', 'часа')} ${m} мин`;
    }
    return `${m} мин ${seconds.value} сек`;
});
</script>

<template>
    <section class="overflow-hidden rounded-2xl bg-[#0a0a0a] text-white shadow-xl">
        <div class="grid lg:grid-cols-2">
            <!-- Писта -->
            <div class="relative order-2 flex items-center justify-center bg-gradient-to-br from-zinc-900 via-zinc-950 to-black p-6 lg:order-1 lg:p-10">
                <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_30%_30%,rgba(225,6,0,0.18),transparent_60%)]" />
                <div
                    v-if="trackSvg"
                    aria-hidden="true"
                    class="relative w-full text-zinc-200 [&_svg]:mx-auto [&_svg]:h-auto [&_svg]:max-h-[24vh] [&_svg]:w-auto [&_svg]:max-w-full lg:[&_svg]:max-h-[50vh]"
                    v-html="trackSvg"
                />
                <div v-else class="relative flex h-44 w-full items-center justify-center rounded-lg border border-dashed border-zinc-700 text-zinc-500">
                    <span class="text-sm">{{ hero.race?.circuit ?? 'Падок' }}</span>
                </div>
            </div>

            <!-- Информация -->
            <div class="order-1 flex flex-col justify-center gap-5 p-6 lg:order-2 lg:p-12">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-red-500">{{ kicker }}</div>
                    <h2 class="mt-2 font-display text-3xl font-black leading-tight sm:text-4xl">
                        {{ hero.race?.name ?? 'Сезонът приключи' }}
                    </h2>
                    <p v-if="hero.race" class="mt-1 text-zinc-400">
                        {{ hero.race.circuit }}<span v-if="hero.race.country"> · {{ hero.race.country }}</span>
                    </p>
                    <p v-if="hero.race?.race_at_sofia" class="mt-1 text-sm text-zinc-500">
                        <span aria-hidden="true">🏁</span> Състезание: {{ hero.race.race_at_sofia }} (Sofia)
                    </p>
                </div>

                <Card v-if="hero.countdown_to">
                    <div class="text-xs uppercase tracking-wide text-zinc-400">{{ hero.countdown_label }}</div>
                    <div class="mt-1 font-display text-2xl font-black tabular-nums sm:text-3xl">{{ countdownText }}</div>
                    <div v-if="hero.countdown_at_sofia" class="mt-1 text-xs text-zinc-500">
                        {{ hero.countdown_at_sofia }} (Sofia)
                    </div>
                </Card>

                <div v-if="winner" class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-sm">
                    <span aria-hidden="true">🏆</span> Победител: <span class="font-semibold">{{ winner.name }}</span>
                </div>

                <div v-if="sessions.length" class="space-y-1">
                    <div class="text-xs uppercase tracking-wide text-zinc-400">Предстоящи сесии</div>
                    <ul class="divide-y divide-zinc-800 text-sm">
                        <li v-for="s in sessions" :key="s.type" class="flex justify-between py-1.5">
                            <span class="text-zinc-300">{{ s.label }}</span>
                            <span class="font-medium">{{ s.at_sofia }}</span>
                        </li>
                    </ul>
                </div>

                <div v-if="hero.race">
                    <Link
                        :href="route('races.show', hero.race.id)"
                        class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-5 py-2.5 font-semibold text-white transition hover:bg-red-500"
                    >
                        {{ ctaLabel }}
                    </Link>
                </div>
            </div>
        </div>
    </section>
</template>
