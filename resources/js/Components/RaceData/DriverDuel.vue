<script setup>
import LapLineChart from '@/Components/RaceData/LapLineChart.vue';
import StintBars from '@/Components/RaceData/StintBars.vue';
import { bg, lapTime } from '@/utils/chart';
import { tyre, tyreHex } from '@/utils/tyres';
import { computed, ref } from 'vue';

/**
 * Двубой в кръга: двама пилоти един срещу друг в рамките на едно състезание.
 *
 * Гледа само този уикенд. Кариерните двубои живеят на друга страница и с друг
 * източник — тук всичко излиза от времената по обиколка на самото състезание и
 * нищо не се смесва с класирането.
 *
 * Сметките са на клиента нарочно: сървърът праща по една серия с времена на
 * пилот и една константа за горивото, а всяка от стотиците възможни двойки
 * би била отделна серия в базата.
 *
 * Чисти сметки — нищо от браузъра и нищо случайно, за да мине и сървърният
 * рендер.
 */
const props = defineProps({
    /** `charts.laps` — суровите времена по обиколка: [{number, name, short, colour, points}]. */
    laps: { type: Array, required: true },
    /** `charts.stints` — стратегията по гуми заедно с обиколките на питстоповете. */
    stints: { type: Array, default: () => [] },
    /** `charts.pace` — медианата на чистите обиколки по пилот. */
    pace: { type: Array, default: () => [] },
    /** `facts.per_driver` — обект с номера на пилота като СТРИНГ ключ (така излиза от JSON). */
    perDriver: { type: Object, default: () => ({}) },
    /** `charts.positions` — тук се ползва само за реда на имената в менютата. */
    positions: { type: Array, default: () => [] },
    /** `charts.fuel_correction` — секундите на обиколка, които болидът печели само от олекване. */
    fuelCorrection: { type: Number, default: 0 },
    totalLaps: { type: Number, default: 0 },
    /** Прозорците на неутрализация като [{from, to}] в обиколки. */
    neutralisations: { type: Array, default: () => [] },
    /** Номер на пилот за лявото меню — любимият пилот на влезлия. */
    preselect: { type: Number, default: null },
});

/** Под толкова обиколки в стинт наклонът е шум, а не деградация. */
const MIN_STINT_LAPS = 4;

/**
 * Пилотите в реда на финиширане. `positions` носи този ред за целия сайт —
 * менютата тук не бива да подреждат хората другояче от съседните графики.
 */
const drivers = computed(() => {
    const remaining = new Map(props.laps.map((row) => [row.number, row]));
    const ordered = [];

    for (const row of props.positions) {
        const driver = remaining.get(row.number);

        if (driver) {
            ordered.push(driver);
            remaining.delete(row.number);
        }
    }

    return [...ordered, ...remaining.values()];
});

const defaults = computed(() => {
    const numbers = drivers.value.map((driver) => driver.number);
    const first = numbers.includes(props.preselect) ? props.preselect : numbers[0];

    return [first ?? null, numbers.find((number) => number !== first) ?? null];
});

const left = ref(defaults.value[0]);
const right = ref(defaults.value[1]);

const byNumber = computed(() => new Map(drivers.value.map((driver) => [driver.number, driver])));

const pair = computed(() => [byNumber.value.get(left.value), byNumber.value.get(right.value)].filter(Boolean));

const ready = computed(() => pair.value.length === 2);

const choose = (side, value) => {
    const number = Number(value);
    const own = side === 'left' ? left : right;
    const other = side === 'left' ? right : left;

    // Един и същ пилот от двете страни не е двубой: другото меню поема този,
    // който току-що е бил освободен.
    if (other.value === number) {
        other.value = own.value;
    }

    own.value = number;
};

const neutralisedLaps = computed(() => {
    const laps = new Set();

    // Името е `band`, а не `window`: локална променлива с това име засенчва
    // глобалния обект и оттам никой — нито човек, нито grep — не може да каже
    // дали компонентът пипа браузъра, а точно това го прави годен за SSR.
    for (const band of props.neutralisations) {
        for (let lap = band.from; Number.isFinite(lap) && lap <= band.to; lap += 1) {
            laps.add(lap);
        }
    }

    return laps;
});

const stintsByNumber = computed(() => new Map(props.stints.map((row) => [row.number, row])));

/**
 * Обиколките на пилота, които не описват темпото му: тази с влизането в пита и
 * следващата след нея.
 *
 * Сървърът маха същите две в `cleanLaps()`, но там изходната се познава по
 * `is_pit_out_lap`, а до клиента идват само номерата на спиранията — оттук и
 * `+1`. Без нея всеки стинт започва с фалшив връх от пит лейна.
 */
const pitLaps = (number) => {
    const laps = new Set();

    for (const lap of stintsByNumber.value.get(number)?.pits ?? []) {
        laps.add(lap);
        laps.add(lap + 1);
    }

    return laps;
};

/**
 * Времената на двамата избрани, изчистени от питстоповете и неутрализациите.
 *
 * Сървърът праща обиколките сурови нарочно — отсяването е тук, защото една
 * обиколка на влизане е с 25 секунди по-бавна и сама смачква цялата графика.
 */
const cleaned = computed(() =>
    pair.value.map((driver) => {
        const dirty = pitLaps(driver.number);
        const points = driver.points.filter(
            ([lap]) => !dirty.has(lap) && !neutralisedLaps.value.has(lap),
        );

        return { driver, points, times: new Map(points) };
    }),
);

const timeSeries = computed(() =>
    cleaned.value.map(({ driver, points }, index) => ({
        number: driver.number,
        name: driver.name,
        short: driver.short,
        colour: driver.colour,
        // Съотборниците делят цвета на отбора — без пунктира двете линии са една.
        dashed: index === 1 && driver.colour === cleaned.value[0].driver.colour,
        points,
    })),
);

/**
 * Разликата в самото време на обиколка, не натрупаното изоставане: натрупаното
 * се вижда в „Изоставане от лидера“, а тук интересното е кой е бил по-бърз в
 * коя част от състезанието.
 */
const deltaSeries = computed(() => {
    if (!ready.value) {
        return [];
    }

    const [first, second] = cleaned.value;
    const points = [];

    for (const [lap, seconds] of first.times) {
        const rival = second.times.get(lap);

        if (rival !== undefined) {
            points.push([lap, Number((seconds - rival).toFixed(3))]);
        }
    }

    // Под две общи обиколки няма линия, има точка.
    if (points.length < 2) {
        return [];
    }

    return [
        {
            number: first.driver.number,
            name: `${first.driver.short} срещу ${second.driver.short}`,
            short: `${first.driver.short} − ${second.driver.short}`,
            // Неутрален цвят: смисълът тук носи знакът, не пилотът.
            colour: '#a1a1aa',
            points,
        },
    ];
});

const median = (values) => {
    const sorted = [...values].sort((a, b) => a - b);

    return sorted[Math.floor(sorted.length / 2)];
};

/**
 * Наклонът на времето спрямо възрастта на гумата по метода на най-малките
 * квадрати — секунди, губени на обиколка.
 */
const lossPerLap = (points) => {
    if (points.length < MIN_STINT_LAPS) {
        return null;
    }

    const meanAge = points.reduce((sum, [age]) => sum + age, 0) / points.length;
    const meanTime = points.reduce((sum, [, time]) => sum + time, 0) / points.length;

    let covariance = 0;
    let variance = 0;

    for (const [age, time] of points) {
        covariance += (age - meanAge) * (time - meanTime);
        variance += (age - meanAge) ** 2;
    }

    return variance === 0 ? null : covariance / variance;
};

/**
 * Деградацията на всеки стинт поотделно.
 *
 * Оста е ВЪЗРАСТТА на гумата, не номерът на обиколката: тя е причината времето
 * да се влошава. Към времето се връща и това, което горивото е спестило —
 * болидът олеква с всяка обиколка и без корекцията наклонът излиза отрицателен,
 * тоест гумата уж се подобрява с износването.
 */
const degradation = computed(() =>
    cleaned.value.map(({ driver, times }) => ({
        driver,
        stints: (stintsByNumber.value.get(driver.number)?.segments ?? []).map((segment) => {
            const byAge = new Map();

            for (let lap = segment.from; lap <= segment.to; lap += 1) {
                const seconds = times.get(lap);

                if (seconds === undefined) {
                    continue;
                }

                const age = (segment.age ?? 0) + (lap - segment.from);
                const corrected = seconds + (lap - 1) * props.fuelCorrection;

                byAge.set(age, [...(byAge.get(age) ?? []), corrected]);
            }

            // Една възраст носи една стойност: повторени записи за същата
            // обиколка иначе тежат двойно на наклона.
            const points = [...byAge.entries()]
                .map(([age, values]) => [age, median(values)])
                .sort((a, b) => a[0] - b[0]);

            return {
                compound: segment.compound,
                from: segment.from,
                to: segment.to,
                laps: points.length,
                loss: lossPerLap(points),
            };
        }),
    })),
);

const hasDegradation = computed(() =>
    degradation.value.some((row) => row.stints.some((stint) => stint.loss !== null)),
);

const worstLoss = computed(() =>
    Math.max(0.01, ...degradation.value.flatMap((row) => row.stints.map((stint) => Math.abs(stint.loss ?? 0)))),
);

const lossWidth = (loss) => `${Math.max(4, (Math.abs(loss) / worstLoss.value) * 100)}%`;

/** Празният наклон има две различни причини и мълчаливото тире крие и двете. */
const lossLabel = (stint) => {
    if (stint.loss !== null) {
        // Знакът се взима СЛЕД закръглянето: наклон от −0,004 иначе се показва
        // като „−0,00 с/об.“, което изглежда като сметка, ударила в грешка.
        const value = Number(stint.loss.toFixed(2));

        return `${value > 0 ? '+' : ''}${bg(value, 2)} с/об.`;
    }

    return stint.laps < MIN_STINT_LAPS ? `под ${MIN_STINT_LAPS} чисти обиколки` : '—';
};

const compoundLabel = (compound) => tyre(compound)?.label ?? compound;

const pairStints = computed(() =>
    pair.value.map((driver) => stintsByNumber.value.get(driver.number)).filter(Boolean),
);

const stintLaps = computed(() => {
    if (props.totalLaps > 0) {
        return props.totalLaps;
    }

    // Липсващият `total_laps` не бива да свива лентите до нула: последната
    // изминала обиколка е достатъчно добра мярка за дължината на състезанието.
    return Math.max(1, ...props.laps.flatMap((row) => row.points.map(([lap]) => lap)));
});

/**
 * Числата един срещу друг. Ред без нито една стойност не се показва — празен
 * ред с две тирета само заема място на телефон.
 */
const numbers = computed(() => {
    if (!ready.value) {
        return [];
    }

    const facts = (driver) => props.perDriver[String(driver.number)] ?? {};
    const paceOf = (driver) => props.pace.find((row) => row.number === driver.number) ?? null;

    const rows = [
        {
            label: 'Най-бърза обиколка',
            better: 'low',
            cells: pair.value.map((driver) => ({
                value: facts(driver).fastest_lap_display ?? null,
                note: facts(driver).fastest_lap_number ? `обиколка ${facts(driver).fastest_lap_number}` : null,
                raw: facts(driver).fastest_lap ?? null,
            })),
        },
        {
            label: 'Най-висока скорост',
            better: 'high',
            cells: pair.value.map((driver) => ({
                value: facts(driver).top_speed ? `${facts(driver).top_speed} км/ч` : null,
                note: null,
                raw: facts(driver).top_speed ?? null,
            })),
        },
        {
            label: 'Медианно темпо',
            better: 'low',
            cells: pair.value.map((driver) => {
                const row = paceOf(driver);

                return {
                    value: row ? lapTime(row.median) : null,
                    note: row && row.delta > 0 ? `+${bg(row.delta, 3)} с от най-бързия` : null,
                    raw: row?.median ?? null,
                };
            }),
        },
        {
            // Спиранията нямат по-добра страна: една спирка по-малко е избор на
            // стратегия, а не предимство.
            label: 'Спирания',
            better: null,
            cells: pair.value.map((driver) => {
                const pits = stintsByNumber.value.get(driver.number)?.pits ?? null;

                return {
                    value: pits === null ? null : String(pits.length),
                    note: pits?.length ? `обиколки ${pits.join(', ')}` : null,
                    raw: null,
                };
            }),
        },
    ];

    return rows.filter((row) => row.cells.some((cell) => cell.value !== null));
});

/** Кой от двамата води в този ред; null = редът няма победител. */
const leader = (row) => {
    if (!row.better) {
        return null;
    }

    const [first, second] = row.cells.map((cell) => cell.raw);

    if (!Number.isFinite(first) || !Number.isFinite(second) || first === second) {
        return null;
    }

    return (row.better === 'low' ? first < second : first > second) ? 0 : 1;
};
</script>

<template>
    <div>
        <div class="flex flex-wrap items-center gap-2">
            <!-- `pr-9` пази стрелката на @tailwindcss/forms да не легне върху
                 името: тя е background-image отдясно, не отделен елемент. -->
            <select
                :value="left"
                aria-label="Пилот отляво"
                class="min-w-0 flex-1 rounded-lg border border-zinc-800 bg-zinc-900 py-2 pl-3 pr-9 text-sm text-white focus:border-red-600 focus:outline-none focus:ring-1 focus:ring-red-600 sm:flex-none"
                @change="choose('left', $event.target.value)"
            >
                <option v-for="driver in drivers" :key="driver.number" :value="driver.number">
                    {{ driver.name }}
                </option>
            </select>

            <span class="shrink-0 text-xs uppercase tracking-wide text-zinc-500">срещу</span>

            <select
                :value="right"
                aria-label="Пилот отдясно"
                class="min-w-0 flex-1 rounded-lg border border-zinc-800 bg-zinc-900 py-2 pl-3 pr-9 text-sm text-white focus:border-red-600 focus:outline-none focus:ring-1 focus:ring-red-600 sm:flex-none"
                @change="choose('right', $event.target.value)"
            >
                <option v-for="driver in drivers" :key="driver.number" :value="driver.number">
                    {{ driver.name }}
                </option>
            </select>
        </div>

        <p v-if="!ready" class="mt-4 text-sm text-zinc-500">
            Състезанието няма обиколкови данни за двама пилоти.
        </p>

        <template v-else>
            <dl v-if="numbers.length" class="mt-4 overflow-hidden rounded-xl border border-zinc-800">
                <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-x-3 bg-zinc-900/60 px-3 py-2">
                    <p class="truncate font-mono text-sm font-semibold" :style="{ color: pair[0].colour }">
                        {{ pair[0].short }}
                    </p>
                    <span aria-hidden="true" />
                    <p class="truncate text-right font-mono text-sm font-semibold" :style="{ color: pair[1].colour }">
                        {{ pair[1].short }}
                    </p>
                </div>

                <div
                    v-for="row in numbers"
                    :key="row.label"
                    class="grid grid-cols-[1fr_auto_1fr] items-center gap-x-3 border-t border-zinc-800 px-3 py-2.5"
                >
                    <dd :class="leader(row) === 0 ? 'text-white' : 'text-zinc-400'">
                        <span class="font-mono text-sm tabular-nums sm:text-base">{{ row.cells[0].value ?? '—' }}</span>
                        <span v-if="row.cells[0].note" class="mt-0.5 block text-[11px] text-zinc-500">
                            {{ row.cells[0].note }}
                        </span>
                    </dd>

                    <dt class="text-center text-[11px] uppercase tracking-wide text-zinc-500">{{ row.label }}</dt>

                    <dd class="text-right" :class="leader(row) === 1 ? 'text-white' : 'text-zinc-400'">
                        <span class="font-mono text-sm tabular-nums sm:text-base">{{ row.cells[1].value ?? '—' }}</span>
                        <span v-if="row.cells[1].note" class="mt-0.5 block text-[11px] text-zinc-500">
                            {{ row.cells[1].note }}
                        </span>
                    </dd>
                </div>
            </dl>

            <section class="mt-6">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-400">Времена по обиколка</h3>
                <p class="mt-1 max-w-prose text-sm text-zinc-500">
                    Само чистите обиколки. Влизането в пита, излизането от него и всичко под неутрализация са извадени —
                    една обиколка на влизане е с двадесет секунди по-бавна и сама изравнява останалите.
                </p>

                <div class="mt-3">
                    <LapLineChart :series="timeSeries" :bands="neutralisations" y-unit=" с" :digits="1" />
                </div>
            </section>

            <section v-if="deltaSeries.length" class="mt-6">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-400">Разлика по обиколка</h3>
                <p class="mt-1 max-w-prose text-sm text-zinc-500">
                    Секундите между двамата в самата обиколка, не натрупани. Нагоре са обиколките, в които
                    {{ pair[0].short }} е бил по-бърз.
                </p>

                <div class="mt-3">
                    <LapLineChart :series="deltaSeries" :bands="neutralisations" y-unit=" с" :digits="1" />
                </div>
            </section>

            <section v-if="pairStints.length" class="mt-6">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-400">Гуми и спирания</h3>

                <div class="mt-3">
                    <StintBars :stints="pairStints" :total-laps="stintLaps" :neutralisations="neutralisations" />
                </div>
            </section>

            <section v-if="hasDegradation" class="mt-6">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-400">Деградация по стинт</h3>
                <p class="mt-1 max-w-prose text-sm text-zinc-500">
                    Колко секунди на обиколка губи всеки от двамата, докато гумата стои под него. Времената са
                    изчистени от олекването на болида, иначе късният стинт излиза по-бърз от ранния.
                </p>

                <div v-for="row in degradation" :key="row.driver.number" class="mt-4">
                    <p class="font-mono text-[11px] font-semibold" :style="{ color: row.driver.colour }">
                        {{ row.driver.short }}
                    </p>

                    <ul class="mt-1.5 space-y-1.5">
                        <li
                            v-for="(stint, index) in row.stints"
                            :key="index"
                            class="flex flex-wrap items-center gap-x-2 gap-y-1"
                        >
                            <i
                                class="inline-block h-2.5 w-2.5 shrink-0 rounded-sm"
                                :style="{ backgroundColor: tyreHex(stint.compound) }"
                                aria-hidden="true"
                            />
                            <span class="w-16 shrink-0 text-xs text-zinc-400">{{ compoundLabel(stint.compound) }}</span>
                            <span class="shrink-0 font-mono text-[11px] tabular-nums text-zinc-500">
                                {{ stint.from }}–{{ stint.to }}
                            </span>

                            <div class="h-2 min-w-[3rem] grow overflow-hidden rounded bg-zinc-800/60">
                                <span
                                    v-if="stint.loss !== null"
                                    class="block h-full opacity-80"
                                    :style="{
                                        width: lossWidth(stint.loss),
                                        backgroundColor: stint.loss > 0 ? '#e4e4e7' : '#34d399',
                                    }"
                                />
                            </div>

                            <span
                                class="w-28 shrink-0 text-right font-mono text-[11px] tabular-nums"
                                :class="stint.loss === null ? 'text-zinc-600' : 'text-zinc-300'"
                            >
                                {{ lossLabel(stint) }}
                            </span>
                        </li>
                    </ul>
                </div>
            </section>
        </template>
    </div>
</template>
