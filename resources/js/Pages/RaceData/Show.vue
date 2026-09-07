<script setup>
import ChampionshipSwing from '@/Components/RaceData/ChampionshipSwing.vue';
import ChartFrame from '@/Components/RaceData/ChartFrame.vue';
import FactTile from '@/Components/RaceData/FactTile.vue';
import LapLineChart from '@/Components/RaceData/LapLineChart.vue';
import PaceBars from '@/Components/RaceData/PaceBars.vue';
import PositionSwing from '@/Components/RaceData/PositionSwing.vue';
import StintBars from '@/Components/RaceData/StintBars.vue';
import TelemetryTrace from '@/Components/RaceData/TelemetryTrace.vue';
import TrackSpeedMap from '@/Components/RaceData/TrackSpeedMap.vue';
import TrackTemperature from '@/Components/RaceData/TrackTemperature.vue';
import TyreDegradation from '@/Components/RaceData/TyreDegradation.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { bg } from '@/utils/chart';
import { hasRoute } from '@/utils/routes';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    race: { type: Object, required: true },
    headline: { type: String, default: null },
    body: { type: Array, default: () => [] },
    facts: { type: Object, default: () => ({}) },
    charts: { type: Object, default: () => ({}) },
    newsSlug: { type: String, default: null },
    neighbours: { type: Object, default: () => ({ prev: null, next: null }) },
});

const bands = computed(() => props.charts.neutralisations ?? []);

/**
 * Плочките горе са резюмето на страницата — човек, който няма да скролне,
 * трябва да излезе с тези четири числа. Липсващ факт просто не заема място.
 */
const tiles = computed(() => {
    const out = [];
    const f = props.facts;

    if (f.top_speed) {
        out.push({ label: 'Най-висока скорост', value: `${f.top_speed.kmh} км/ч`, note: f.top_speed.name, colour: f.top_speed.colour });
    }

    if (f.fastest_lap) {
        out.push({ label: 'Най-бърза обиколка', value: f.fastest_lap.display, note: f.fastest_lap.name, colour: f.fastest_lap.colour });
    }

    if (f.winner?.gap_to_second != null) {
        out.push({ label: 'Разлика до втория', value: `${bg(f.winner.gap_to_second, 3)} с`, note: f.winner.name, colour: f.winner.colour });
    }

    if (f.movers?.climber) {
        out.push({
            label: 'Най-голямо изкачване',
            value: `+${f.movers.climber.gained}`,
            note: `${f.movers.climber.name} · ${f.movers.climber.from} → ${f.movers.climber.to}`,
            colour: f.movers.climber.colour,
        });
    }

    if (f.pit?.fastest_lane) {
        out.push({ label: 'Най-кратък пит лейн', value: `${bg(f.pit.fastest_lane.seconds)} с`, note: f.pit.fastest_lane.name });
    }

    if (f.neutralisations) {
        out.push({ label: 'Неутрализации', value: String(f.neutralisations.count), note: `${f.neutralisations.laps} обиколки` });
    }

    if (f.battle) {
        out.push({
            label: 'Най-дълга битка',
            value: `${f.battle.minutes} мин`,
            note: `${f.battle.name} — под секунда зад предния`,
            colour: f.battle.colour,
        });
    }

    return out;
});
</script>

<template>
    <PublicLayout>
        <header class="mb-6">
            <Link
                v-if="hasRoute('racedata.index')"
                :href="route('racedata.index')"
                class="text-sm text-zinc-500 transition hover:text-zinc-300"
            >
                ← Данни
            </Link>

            <h1 class="mt-2 font-display text-2xl font-black text-white sm:text-3xl">
                {{ headline }}
            </h1>

            <p class="mt-1 text-sm text-zinc-500">
                <template v-if="race.round">Кръг {{ race.round }} · </template>
                <template v-if="race.circuit">{{ race.circuit }} · </template>
                {{ race.date }}
            </p>
        </header>

        <!-- Победителят е първото, което човек търси след състезание. -->
        <div
            v-if="facts.winner"
            class="mb-6 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-2xl border border-zinc-800 bg-zinc-900/60 p-4"
        >
            <span class="h-8 w-1.5 rounded-full" :style="{ backgroundColor: facts.winner.colour }" aria-hidden="true" />
            <div class="min-w-0">
                <p class="text-xs uppercase tracking-wide text-zinc-500">Победител</p>
                <p class="font-display text-xl font-black text-white">{{ facts.winner.name }}</p>
            </div>
            <p v-if="facts.winner.team" class="ml-auto text-sm text-zinc-400">{{ facts.winner.team }}</p>
        </div>

        <div v-if="tiles.length" class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <FactTile
                v-for="tile in tiles"
                :key="tile.label"
                :label="tile.label"
                :value="tile.value"
                :note="tile.note"
                :colour="tile.colour"
            />
        </div>

        <div v-if="body.length" class="mb-8 space-y-4 text-lg leading-relaxed text-zinc-200">
            <p v-for="(paragraph, i) in body" :key="i">{{ paragraph }}</p>
        </div>

        <div class="space-y-5">
            <ChartFrame
                v-if="charts.track_map?.points?.length"
                title="Пистата, оцветена по скорост"
                hint="Реалната линия на болида през най-бързата обиколка. Цветът е скоростта — тъмното е спирачка, светлото е права. Числата са завоите."
            >
                <TrackSpeedMap
                    :points="charts.track_map.points"
                    :outline="charts.track_map.outline"
                    :corners="charts.track_map.corners"
                    :rotation="charts.track_map.rotation"
                    :driver="charts.track_map.driver"
                />
            </ChartFrame>

            <ChartFrame
                v-if="charts.telemetry?.length"
                title="Телеметрия на обиколката"
                hint="Скорост, газ и предавка по дистанция. Трите панела делят една ос — падни с поглед надолу и виждаш какво прави пилотът в същата точка от пистата."
            >
                <TelemetryTrace :series="charts.telemetry" />
            </ChartFrame>

            <ChartFrame
                v-if="charts.stints?.length"
                title="Стратегия по гуми"
                hint="Всяка лента е един пилот от старта до финала. Цветът е съставът на гумата, тънката резка е питстоп, жълтият фон е неутрализация."
            >
                <StintBars :stints="charts.stints" :total-laps="charts.total_laps" :neutralisations="bands" />
            </ChartFrame>

            <ChartFrame
                v-if="charts.tyre_degradation?.compounds?.length"
                title="Деградация на гумите"
                hint="Колко се влошава времето с износването. По хоризонталата е възрастта на гумата, не обиколката — така стинтове от различни моменти стават сравними. Времената са коригирани за изразходено гориво."
            >
                <TyreDegradation :compounds="charts.tyre_degradation.compounds" />
            </ChartFrame>

            <ChartFrame
                v-if="charts.positions?.length"
                title="Позиции по обиколки"
                hint="Кой къде е бил след всяка обиколка. Докосни име, за да откроиш един пилот."
            >
                <LapLineChart :series="charts.positions" :bands="bands" :y-ticks="4" />
            </ChartFrame>

            <ChartFrame
                v-if="charts.trace?.length"
                title="Изоставане от лидера"
                hint="Колко секунди зад водача е бил всеки от челото. Ръбовете надолу са питстопове, сближаването е неутрализация."
            >
                <LapLineChart :series="charts.trace" :bands="bands" y-unit=" с" :digits="0" />
            </ChartFrame>

            <ChartFrame
                v-if="charts.pace?.length"
                title="Реално темпо"
                hint="Медианата на чистите обиколки — без излизане от пита, без обиколката на влизане и без нищо под safety car. Класацията казва кой е финиширал пръв; това казва кой е бил бърз."
            >
                <PaceBars :rows="charts.pace" />
            </ChartFrame>

            <ChartFrame
                v-if="charts.grid_vs_finish?.length"
                title="Спечелени и загубени позиции"
                hint="Разликата между мястото на старта и мястото на финала."
            >
                <PositionSwing :rows="charts.grid_vs_finish" />
            </ChartFrame>

            <ChartFrame
                v-if="charts.championship?.length"
                title="Шампионатът след кръга"
                hint="Плътната част на лентата е спечеленото в това състезание."
            >
                <ChampionshipSwing :rows="charts.championship" />
            </ChartFrame>

            <ChartFrame
                v-if="charts.championship_teams?.length"
                title="Конструкторите след кръга"
                hint="Същото при отборите — плътната част е спечеленото този уикенд."
            >
                <ChampionshipSwing :rows="charts.championship_teams" labels="wide" />
            </ChartFrame>

            <ChartFrame
                v-if="charts.weather?.points?.length"
                title="Асфалтът през сесията"
                hint="Записът тръгва час преди старта, така че се вижда как пистата се загрява и после охлажда."
            >
                <TrackTemperature :points="charts.weather.points" />
            </ChartFrame>
        </div>

        <!-- CC BY-NC-SA изисква посочване на източника, и то до данните, а не в
             футъра на сайта. -->
        <p class="mt-6 text-xs text-zinc-600">
            Данни:
            <a href="https://openf1.org" rel="noopener nofollow" class="transition hover:text-zinc-400">openf1.org</a>
            · CC BY-NC-SA 4.0. Изчисленията са наши.
        </p>

        <div class="mt-4 flex flex-wrap gap-x-4 gap-y-2 text-sm">
            <Link :href="route('races.show', race.id)" class="text-red-500 transition hover:text-red-400">
                Класацията от кръга →
            </Link>
            <Link v-if="newsSlug" :href="route('news.show', newsSlug)" class="text-zinc-500 transition hover:text-zinc-300">
                Статията в новините →
            </Link>
        </div>

        <nav v-if="neighbours.prev || neighbours.next" class="mt-8 grid gap-3 sm:grid-cols-2">
            <Link
                v-if="neighbours.prev"
                :href="route('racedata.show', neighbours.prev.id)"
                class="rounded-xl border border-zinc-800 p-4 transition duration-200 hover:border-red-600/50 hover:bg-zinc-900"
            >
                <span class="text-xs uppercase tracking-wide text-zinc-500">← Предишен кръг</span>
                <span class="mt-1 block font-medium text-white">{{ neighbours.prev.name }}</span>
            </Link>
            <Link
                v-if="neighbours.next"
                :href="route('racedata.show', neighbours.next.id)"
                class="rounded-xl border border-zinc-800 p-4 text-right transition duration-200 hover:border-red-600/50 hover:bg-zinc-900 sm:col-start-2"
            >
                <span class="text-xs uppercase tracking-wide text-zinc-500">Следващ кръг →</span>
                <span class="mt-1 block font-medium text-white">{{ neighbours.next.name }}</span>
            </Link>
        </nav>
    </PublicLayout>
</template>
