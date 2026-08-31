<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { isMobileDevice } from '@/game/device.js';
import { formatDelta, formatLapTime } from '@/game/format.js';
import { Head, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';

const props = defineProps({
    tracks: { type: Array, default: () => [] },
    // Slug на „пистата на уикенда" — там, където Ф1 кара в момента.
    weekTrack: { type: String, default: null },
});

// Пистата на уикенда изплува първа в списъка.
const orderedTracks = computed(() => {
    if (!props.weekTrack) {
        return props.tracks;
    }
    const week = props.tracks.filter((t) => t.slug === props.weekTrack);
    const rest = props.tracks.filter((t) => t.slug !== props.weekTrack);
    return [...week, ...rest];
});

const page = usePage();
const authUser = computed(() => page.props.auth?.user ?? null);

const canvas = ref(null);
const game = shallowRef(null);
const selectedTrack = ref(null);
const loading = ref(false);
const loadProgress = ref(0); // 0..1 — реални байтове (болид/среда/текстури)
const error = ref(null);
const transmission = ref('auto'); // 'auto' | 'manual' (ръчна: W нагоре, S надолу)
const rivals = ref('race'); // 'race' (AI съперници на пистата) | 'solo' (чиста обиколка)
const RIVAL_COUNT = 5;
// Мобилно управление: накланяне на телефона или екранни бутони ◀ ▶.
const controlMode = ref('tilt'); // 'tilt' | 'buttons'
const preStart = ref(false); // pre-start екран (избор трансмисия + управление) преди обиколката
const isMobile = ref(false); // телефон/тъч → tilt завиване + авто-газ, скрити ръчни
const tiltError = ref(false); // накланянето не е достъпно/разрешено

onMounted(() => {
    // Общият детектор с Game.js (device.js) — двете преценки не бива да се
    // разминават (мобилни контроли + десктоп рендер на iPad).
    isMobile.value = isMobileDevice();

    // Ръчните скорости са неудобни на телефон — само авто.
    if (isMobile.value) {
        transmission.value = 'auto';
    }

    // Линк-покана за дуел: /game?track=monza&rival=12 отваря пистата направо
    // срещу духа на съперника.
    const params = new URLSearchParams(window.location.search);
    const trackParam = params.get('track');
    const rivalParam = params.get('rival');
    if (trackParam) {
        const track = props.tracks.find((t) => t.slug === trackParam);
        if (track) {
            const rivalId = rivalParam && /^\d+$/.test(rivalParam) ? Number(rivalParam) : null;
            startGame(track, rivalId);
        }
    }
});

const emptyTelemetry = () => ({
    speed: 0,
    rpm: 4000,
    gear: 1,
    position: 1,
    fieldSize: 1,
    raceLap: 0,
    raceTotalLaps: 0,
    tower: null,
    ghostDelta: null,
    mapDots: [],
    lapTime: null,
    lastLap: null,
    bestLap: null,
    sector: 1,
    sectors: [null, null, null],
    lapValid: true,
    started: false,
    phase: 'formation',
    recovering: false,
    recoverCount: 0,
    gated: false,
    warnings: 0,
    maxWarnings: 3,
});

const telemetry = ref(emptyTelemetry());

// ── Мини-картата: пътят се рисува веднъж, точките — на всяко обновяване ───
const minimapCanvas = ref(null);
const MINIMAP_SIZE = 128;
// Играч / бот / официален дух (златист) / личен дух (син) / дуелен (фуксия) —
// типът идва от Game (mapDots.t) и следва цвета на 3D духа.
const DOT_COLORS = ['#e10600', '#9aa3ad', '#f2c14e', '#9fc8ff', '#e879f9'];

const drawMinimapPath = () => {
    drawMinimap(telemetry.value);
};

const drawMinimap = (values) => {
    const canvas = minimapCanvas.value;
    const path = game.value?.minimap?.path;
    if (!canvas || !path) {
        return;
    }

    const ctx = canvas.getContext('2d');
    const s = MINIMAP_SIZE;
    const pad = 8;
    const scale = s - pad * 2;
    ctx.clearRect(0, 0, s, s);

    ctx.beginPath();
    for (let i = 0; i < path.length; i++) {
        const [x, y] = path[i];
        if (i === 0) {
            ctx.moveTo(pad + x * scale, pad + y * scale);
        } else {
            ctx.lineTo(pad + x * scale, pad + y * scale);
        }
    }
    ctx.closePath();
    ctx.strokeStyle = 'rgba(255,255,255,0.55)';
    ctx.lineWidth = 2;
    ctx.stroke();

    for (const dot of values.mapDots ?? []) {
        ctx.beginPath();
        ctx.arc(pad + dot.x * scale, pad + dot.y * scale, dot.t === 0 ? 4 : 3, 0, Math.PI * 2);
        ctx.fillStyle = DOT_COLORS[dot.t] ?? '#fff';
        ctx.fill();
    }
};

// ── Класация / резултат ───────────────────────────────────────────────────
// Лилавите рекорди на пистата (обиколка + по сектори), топ класация и резултатът
// от току-що завършената квалификационна обиколка.
const bests = ref({ lap_ms: null, sectors_ms: [null, null, null] });
const userBests = ref({ lap_ms: null, sectors_ms: [null, null, null] });
const leaderboard = ref([]);
const result = ref(null); // { lapMs, sectorsMs: [..], valid }
const resultMeta = ref(null); // отговорът на сървъра: purple_lap, purple_sectors, rank…
const submitting = ref(false);
const submitError = ref(null);

const fetchLeaderboard = async (slug) => {
    try {
        const { data } = await window.axios.get(`/game/leaderboard/${slug}`);
        bests.value = data.bests ?? { lap_ms: null, sectors_ms: [null, null, null] };
        userBests.value = data.user_bests ?? { lap_ms: null, sectors_ms: [null, null, null] };
        leaderboard.value = data.top ?? [];
    } catch {
        // Класацията е бонус — липсата ѝ не бива да чупи играта.
        bests.value = { lap_ms: null, sectors_ms: [null, null, null] };
        userBests.value = { lap_ms: null, sectors_ms: [null, null, null] };
        leaderboard.value = [];
    }
};

// Финал на квалификационната обиколка → резултатен екран + (ако е валидна и има
// вход) запис в класацията.
const onFinish = (res) => {
    result.value = res;
    resultMeta.value = null;
    submitError.value = null;

    // onFinish идва само от соло обиколки (състезанието завършва с подиум,
    // без запис — контактите го правят невъзпроизводимо за валидацията).
    if (res.valid && res.trace && authUser.value) {
        submitLap(res);
    }
};

const submitLap = async (res) => {
    if (!selectedTrack.value) {
        return;
    }

    // Пистата може да се смени, докато заявката лети — тогава отговорът се
    // изхвърля, вместо да пренапише класацията на НОВАТА писта.
    const submittedSlug = selectedTrack.value.slug;

    submitting.value = true;
    submitError.value = null;

    try {
        const { data } = await window.axios.post('/game/lap', {
            track: submittedSlug,
            lap_ms: res.lapMs,
            sectors: res.sectorsMs,
            // Записът на входа — сървърът преиграва обиколката и я потвърждава.
            trace: res.trace,
            sim_version: res.simVersion,
        });

        if (selectedTrack.value?.slug !== submittedSlug) {
            return;
        }

        resultMeta.value = data;
        bests.value = data.bests ?? bests.value; // включва и тази обиколка
        userBests.value = data.user_bests ?? userBests.value;
        leaderboard.value = data.top ?? leaderboard.value;
    } catch (e) {
        if (selectedTrack.value?.slug === submittedSlug) {
            submitError.value =
                e?.response?.data?.message ?? 'Времето не се записа. Опитай пак.';
        }
    } finally {
        submitting.value = false;
    }
};

const newLap = () => {
    replaying.value = false;
    result.value = null;
    resultMeta.value = null;
    submitError.value = null;
    game.value?.reset(true);
};

// Ново състезание от подиума: решетка + светлини отначало.
const newRace = () => {
    replaying.value = false;
    raceResult.value = null;
    game.value?.reset(true);
};

// ── Share-карта за Telegram: PNG на резултата + линк за дуел ──────────────
const sharing = ref(false);

const shareResult = async () => {
    if (!result.value || !selectedTrack.value || sharing.value) {
        return;
    }
    sharing.value = true;
    try {
        const challengeUrl = authUser.value
            ? challengeLink(selectedTrack.value.slug, authUser.value.id)
            : null;
        const { buildShareCard } = await import('@/game/shareCard.js');
        const blob = await buildShareCard({
            trackName: selectedTrack.value.name,
            lapMs: result.value.lapMs,
            sectorsMs: result.value.sectorsMs,
            rank: resultMeta.value?.rank ?? null,
            state: lapState.value,
            challengeUrl,
        });

        const file = new File([blob], 'padok-hronometar.png', { type: 'image/png' });
        if (navigator.canShare?.({ files: [file] })) {
            await navigator.share({ files: [file] });
        } else {
            // Десктоп: сваляне + линкът за дуел в клипборда.
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'padok-hronometar.png';
            link.click();
            URL.revokeObjectURL(url);
            if (challengeUrl) {
                try {
                    await navigator.clipboard.writeText(challengeUrl);
                } catch {
                    // Блокиран клипборд — PNG-то пак е свалено, линкът е и в него.
                }
            }
        }
    } catch {
        // Отказан share диалог/блокиран canvas — нищо.
    } finally {
        sharing.value = false;
    }
};

// Дуел директно от резултатния екран: духът се зарежда в ТЕКУЩАТА игра
// (без ново зареждане на пистата) и тръгва нова обиколка.
const duelFromBoard = async (row) => {
    if (!game.value || !selectedTrack.value || !row.has_ghost) {
        return;
    }
    const instance = game.value;
    try {
        const { data } = await window.axios.get(
            `/game/ghost/${selectedTrack.value.slug}/${row.user_id}`
        );
        if (game.value === instance && instance.setRivalGhost(data)) {
            rivalInfo.value = { name: data.name, lapMs: data.lap_ms };
            newLap();
        }
    } catch {
        // Духът междувременно е изчезнал — нищо.
    }
};

// ── ТВ повторение на завършената обиколка ─────────────────────────────────
const replaying = ref(false);

// ── Стартова процедура (състезание): брой светнали лампи, null = няма ─────
const launchLights = ref(null);

// ── Финал на състезанието: {position, standings} от Game.onRaceFinish ─────
const raceResult = ref(null);

// ── Дуел: духът на съперник от класацията ─────────────────────────────────
const rivalInfo = ref(null); // {name, lapMs} — показва се като чип в HUD-а

// ── Класация на pre-game екрана: разгъната писта + редовете ѝ ─────────────
const expandedBoard = ref(null); // slug на пистата с отворена класация
const boardRows = ref([]);
const boardWeekly = ref(null); // седмичната класация (само пистата на уикенда)
const boardTab = ref('all'); // 'week' | 'all'
const boardLoading = ref(false);

const displayedBoardRows = computed(() =>
    boardTab.value === 'week' && boardWeekly.value !== null ? boardWeekly.value : boardRows.value
);
const copiedChallenge = ref(null); // ключ на реда с копиран линк (за ✓)

const toggleBoard = async (slug) => {
    if (expandedBoard.value === slug) {
        expandedBoard.value = null;
        return;
    }
    expandedBoard.value = slug;
    boardRows.value = [];
    boardWeekly.value = null;
    boardLoading.value = true;
    try {
        const { data } = await window.axios.get(`/game/leaderboard/${slug}`);
        if (expandedBoard.value === slug) {
            boardRows.value = data.top ?? [];
            boardWeekly.value = data.weekly ?? null;
            // Пистата на уикенда отваря направо седмичното предизвикателство.
            boardTab.value = data.weekly !== null && data.weekly !== undefined ? 'week' : 'all';
        }
    } catch {
        if (expandedBoard.value === slug) {
            boardRows.value = [];
            // Без weekly данни табът „Тази седмица" от предишна писта би
            // показал седмично празно съобщение на писта без предизвикателство.
            boardTab.value = 'all';
        }
    } finally {
        // Закъснял отговор за ВЕЧЕ сменена писта не бива да гаси спинера
        // на текущата (и обратно) — всичко е гейтнато по slug-а.
        if (expandedBoard.value === slug) {
            boardLoading.value = false;
        }
    }
};

// Линк-покана: отваря играта директно в дуел срещу духа на потребителя.
const challengeLink = (slug, userId) =>
    `${window.location.origin}/game?track=${encodeURIComponent(slug)}&rival=${userId}`;

let copiedTimer = null;

const copyChallenge = async (slug, userId, key) => {
    try {
        await navigator.clipboard.writeText(challengeLink(slug, userId));
        copiedChallenge.value = key;
        // Един таймер: повторен клик рестартира отброяването, вместо старият
        // таймер да гаси ✓-то предсрочно.
        clearTimeout(copiedTimer);
        copiedTimer = setTimeout(() => {
            copiedChallenge.value = null;
        }, 2500);
    } catch {
        // Клипбордът е блокиран (стар браузър/без HTTPS) — показваме линка.
        window.prompt('Копирай линка за дуела:', challengeLink(slug, userId));
    }
};

const startReplay = () => {
    if (game.value?.startReplay()) {
        replaying.value = true;
    }
};

const stopReplay = () => {
    game.value?.stopReplay();
    replaying.value = false;
};

// Лилаво = рекорд на пистата. Докато сървърът не отговори, сравняваме локално
// спрямо рекордите отпреди обиколката; после ползваме авторитетния отговор.
const lapIsPurple = computed(() => {
    if (!result.value || !result.value.valid) {
        return false;
    }
    if (resultMeta.value) {
        return resultMeta.value.purple_lap;
    }
    // Строго < като сървъра (изравняване не е нов рекорд на пистата).
    return bests.value.lap_ms === null || result.value.lapMs < bests.value.lap_ms;
});

const sectorIsPurple = (i) => {
    if (!result.value || !result.value.valid || result.value.sectorsMs[i] === null) {
        return false;
    }
    if (resultMeta.value) {
        return resultMeta.value.purple_sectors?.[i] ?? false;
    }
    const best = bests.value.sectors_ms[i];
    return best === null || result.value.sectorsMs[i] < best;
};

// Цвят на сектор/обиколка (F1): лилаво = рекорд на всички (има предимство),
// зелено = личен рекорд, жълто = по-бавно от личния рекорд.
const sectorState = (i) => {
    if (!result.value || !result.value.valid || result.value.sectorsMs[i] === null) {
        return 'none';
    }
    if (sectorIsPurple(i)) {
        return 'purple';
    }
    if (resultMeta.value) {
        return resultMeta.value.green_sectors?.[i] ? 'green' : 'yellow';
    }
    const pb = userBests.value.sectors_ms[i];
    return pb === null || result.value.sectorsMs[i] <= pb ? 'green' : 'yellow';
};

const lapState = computed(() => {
    if (!result.value || !result.value.valid) {
        return 'none';
    }
    if (lapIsPurple.value) {
        return 'purple';
    }
    if (resultMeta.value) {
        return resultMeta.value.personal_best ? 'green' : 'yellow';
    }
    const pb = userBests.value.lap_ms;
    return pb === null || result.value.lapMs <= pb ? 'green' : 'yellow';
});

const CELL_CLASS = {
    purple: 'border-fuchsia-500/50 bg-fuchsia-500/10',
    green: 'border-emerald-500/50 bg-emerald-500/10',
    yellow: 'border-amber-500/40 bg-amber-500/10',
    none: 'border-zinc-700 bg-zinc-800/40',
};
const TEXT_CLASS = {
    purple: 'text-fuchsia-300',
    green: 'text-emerald-300',
    yellow: 'text-amber-300',
    none: 'text-zinc-100',
};
const LAP_TEXT_CLASS = {
    purple: 'text-fuchsia-400',
    green: 'text-emerald-400',
    yellow: 'text-amber-400',
    none: 'text-white',
};

const sectorCellClass = (i) => CELL_CLASS[sectorState(i)];
const sectorTextClass = (i) => TEXT_CLASS[sectorState(i)];
const lapTextClass = computed(() => LAP_TEXT_CLASS[lapState.value]);

const formatMs = (ms) => (ms === null || ms === undefined ? '—' : formatLapTime(ms / 1000));
const formatSectorMs = (ms) => (ms === null || ms === undefined ? '—' : (ms / 1000).toFixed(3));

const lastLapDelta = computed(() =>
    formatDelta(telemetry.value.lastLap, telemetry.value.bestLap)
);

// ── Оборотомер + предавка ──────────────────────────────────────────────────
const REDLINE = 15000;
const revFraction = computed(() => Math.min(1, (telemetry.value.rpm ?? 0) / REDLINE));
const atRedline = computed(() => revFraction.value > 0.94);
const gearLabel = computed(() => (telemetry.value.gear === 0 ? 'R' : String(telemetry.value.gear ?? 1)));

// Сегменти на rev-бара с shift-lights: зелено → жълто → червено (последните мигат).
const revSegments = computed(() => {
    const total = 16;
    const filled = Math.round(revFraction.value * total);
    return Array.from({ length: total }, (_, i) => {
        let color = 'bg-emerald-500';
        if (i >= total - 3) {
            color = 'bg-red-500';
        } else if (i >= total - 7) {
            color = 'bg-amber-400';
        }
        return { on: i < filled, color };
    });
});

// Първо място в класацията на пистата (от всички потребители) → трофей.
const isFirstPlace = computed(() => resultMeta.value?.rank === 1);

/**
 * Three.js се зарежда динамично: ~600 KB, които нямат работа в основния
 * бъндъл на сайта, щом играта е една страница от двайсет.
 */
const startGame = async (track, rivalUserId = null) => {
    // Клавиатура/бърз двоен тап: едно зареждане наведнъж.
    if (loading.value || selectedTrack.value) {
        return;
    }
    loading.value = true;
    error.value = null;
    rivalInfo.value = null;

    try {
        const [{ Game }, response] = await Promise.all([
            import('@/game/Game.js'),
            fetch(`/game-tracks/${track.slug}.json`),
        ]);

        if (!response.ok) {
            throw new Error(`Данните за пистата не се заредиха (${response.status}).`);
        }

        const data = await response.json();

        selectedTrack.value = track;
        result.value = null;
        resultMeta.value = null;
        preStart.value = true; // pre-start екран (избор трансмисия + управление) преди старта

        // Лилавите рекорди се теглят фоново — трябват чак на финала.
        fetchLeaderboard(track.slug);

        // Смяната на екрана рендерира canvas-а едва след цикъла на Vue —
        // без това `canvas.value` е още null.
        await nextTick();

        if (!canvas.value) {
            throw new Error('Платното не се инициализира.');
        }

        loadProgress.value = 0;
        game.value = new Game(
            canvas.value,
            data,
            (values) => {
                telemetry.value = values;
                drawMinimap(values);
            },
            onFinish,
            {
                onProgress: (fraction) => {
                    loadProgress.value = fraction;
                },
            }
        );

        // Реплеят може да свърши и отвътре (R рестарт) — сваляме си флага.
        game.value.onReplayEnd = () => {
            replaying.value = false;
        };

        // Светлините на стартовата процедура (само в състезание).
        game.value.onLaunch = (lights) => {
            launchLights.value = lights;
        };

        // Карираният флаг на състезанието → подиумът.
        game.value.onRaceFinish = (result) => {
            raceResult.value = result;
        };

        // Вътрешен reset (R / „Рестарт" по време на реплей) сваля и соло
        // резултатния екран — иначе новата обиколка кара зад стария overlay.
        game.value.onResultClear = () => {
            result.value = null;
            resultMeta.value = null;
            submitError.value = null;
        };

        // Дуел от класацията: духът на съперника се тегли паралелно със
        // зареждането на средата. Дуелът е соло дисциплина (духът се крие в
        // състезание) — селекторът застава на „Сам на пистата", а опцията
        // „Състезание" е деактивирана, докато дуелът е активен.
        if (rivalUserId !== null) {
            rivals.value = 'solo';
            const instance = game.value;
            window.axios
                .get(`/game/ghost/${track.slug}/${rivalUserId}`)
                .then(({ data }) => {
                    if (game.value === instance && instance.setRivalGhost(data)) {
                        rivalInfo.value = { name: data.name, lapMs: data.lap_ms };
                    }
                })
                .catch(() => {
                    // Няма дух (изтрит/невалиден) — караш си нормална обиколка.
                });
        }

        // Изчакай средата (HDRI + болид + текстури) да се зареди. НЕ стартираме
        // тук — стартът чака бутона „Карай" от pre-start екрана (beginLap), след
        // като играчът избере трансмисия. Ако играчът напусне през това време
        // (game.value става null/друга инстанция), не пипаме мъртвата инстанция.
        const instance = game.value;
        await instance.ready?.catch(() => {});
        if (game.value !== instance) {
            return;
        }
        window.addEventListener('resize', handleResize);

        // Духът кара демо зад pre-start екрана, докато избираш настройки.
        instance.startAttract();
        drawMinimapPath();
    } catch (e) {
        error.value = e.message ?? 'Нещо се обърка при зареждането.';
        selectedTrack.value = null;
    } finally {
        loading.value = false;
    }
};

// Пуска обиколката от pre-start екрана: прилага избраната трансмисия и стартира.
const beginLap = () => {
    if (!game.value) {
        return;
    }
    game.value.setTransmission(transmission.value);
    // Съперниците не пипат физиката/хронометъра на играча — чист time trial
    // с трафик. Виж Game.setOpponents за „защо без колизии".
    game.value.setOpponents(rivals.value === 'race' ? RIVAL_COUNT : 0);

    // Телефон: авто-газ + завиване с накланяне ИЛИ екранни бутони (по избор
    // от pre-start екрана). Разрешението за жироскоп (iOS) се иска ТУК,
    // защото тапът на „Карай" е потребителски жест.
    if (isMobile.value) {
        game.value.autoThrottle = true;
        if (controlMode.value === 'tilt') {
            enableTilt();
        }

        // Цял екран + пейзаж (Android; iOS няма API — жестът просто минава).
        // Играта е строена за широк изглед: chase камерата вижда 3× повече.
        try {
            const container = canvas.value?.parentElement;
            container?.requestFullscreen?.().catch(() => {});
            screen.orientation?.lock?.('landscape').catch(() => {});
        } catch {
            // Без поддръжка — нищо.
        }
    }

    preStart.value = false;
    game.value.start();
};

const quit = () => {
    // Мобилният fullscreen + orientation lock (beginLap) не падат сами със
    // смяната на екрана — сайтът оставаше „залепен" в пейзаж на цял екран.
    try {
        document.exitFullscreen?.().catch(() => {});
        screen.orientation?.unlock?.();
    } catch {
        // Не сме във fullscreen / без поддръжка — нищо.
    }
    teardown();
    selectedTrack.value = null;
    preStart.value = false;
    replaying.value = false;
    launchLights.value = null;
    raceResult.value = null;
    rivalInfo.value = null;
    // Панелът с класацията може да е остарял след изкараните обиколки.
    expandedBoard.value = null;
    boardRows.value = [];
    telemetry.value = emptyTelemetry();
    result.value = null;
    resultMeta.value = null;
    leaderboard.value = [];
};

const restart = () => game.value?.reset(true);

const handleResize = () => game.value?.resize();

const teardown = () => {
    window.removeEventListener('resize', handleResize);
    disableTilt();
    game.value?.dispose();
    game.value = null;
};

// Без това всяка навигация из сайта оставя жив WebGL контекст — браузърите
// пазят шепа такива и после отказват да създават нови.
onBeforeUnmount(teardown);

// ── Управление на телефон: накланяне (волан) + бутон спирачка, газта е авто ──
const setInput = (values) => game.value?.setTouchInput(values);
const holdBrake = () => setInput({ brake: 1 });
const releaseBrake = () => setInput({ brake: 0 });
// Екранни бутони за завиване (controlMode === 'buttons'). Пазим състоянието
// на ВСЕКИ бутон: при едновременно натискане пускането на единия не бива да
// нулира другия (мулти-тъч).
const steerHeld = { left: false, right: false };
const applySteer = () =>
    setInput({ steer: (steerHeld.right ? 1 : 0) - (steerHeld.left ? 1 : 0) });
const holdSteer = (direction) => {
    steerHeld[direction < 0 ? 'left' : 'right'] = true;
    applySteer();
};
const releaseSteer = (direction) => {
    steerHeld[direction < 0 ? 'left' : 'right'] = false;
    applySteer();
};

// Аналогов волан от накланянето, устойчив на портрет/пейзаж. Проектираме наклона
// върху ХОРИЗОНТАЛНАТА ОС НА ЕКРАНА (не на устройството): в портрет това е gamma,
// в пейзаж — beta, автоматично според ориентацията. Калибрира се спрямо хвата на
// старта (и при завъртане), мъртва зона ±TILT_DEADZONE°, ±TILT_MAX° = пълен волан.
const TILT_MAX = 28;
const TILT_DEADZONE = 2.5;
const TILT_INVERT = false; // ако на реалния телефон завива наобратно → true
let tiltNeutral = null;

const orientationAngle = () => {
    if (typeof window.screen?.orientation?.angle === 'number') {
        return window.screen.orientation.angle;
    }
    if (typeof window.orientation === 'number') {
        return (((window.orientation % 360) + 360) % 360);
    }

    return 0;
};

const onTilt = (event) => {
    if (
        event.gamma === null || event.gamma === undefined ||
        event.beta === null || event.beta === undefined
    ) {
        return;
    }
    // Наклонът на екрана = проекция на (gamma, beta) върху хоризонталната ос,
    // завъртяна с ориентацията: портрет → gamma, пейзаж → ±beta.
    const rad = (orientationAngle() * Math.PI) / 180;
    const tilt = event.gamma * Math.cos(rad) + event.beta * Math.sin(rad);

    if (tiltNeutral === null) {
        tiltNeutral = tilt; // първи прочит (или след завъртане) = неутрално
    }
    let delta = tilt - tiltNeutral;
    const sign = Math.sign(delta);
    delta = Math.max(0, Math.abs(delta) - TILT_DEADZONE) * sign;
    let steer = Math.max(-1, Math.min(1, delta / (TILT_MAX - TILT_DEADZONE)));
    if (TILT_INVERT) {
        steer = -steer;
    }
    setInput({ steer });
};

// Портрет ↔ пейзаж сменя неутралното положение → рекалибрираме.
const onOrientationChange = () => {
    tiltNeutral = null;
};

const enableTilt = async () => {
    tiltError.value = false;
    tiltNeutral = null; // рекалибрира при следващия прочит
    try {
        // iOS 13+: иска изрично разрешение при потребителски жест.
        if (
            typeof DeviceOrientationEvent !== 'undefined' &&
            typeof DeviceOrientationEvent.requestPermission === 'function'
        ) {
            const res = await DeviceOrientationEvent.requestPermission();
            if (res !== 'granted') {
                fallBackToButtons();

                return;
            }
        }
        window.addEventListener('deviceorientation', onTilt);
        window.addEventListener('orientationchange', onOrientationChange);
    } catch {
        fallBackToButtons();
    }
};

// Отказано/недостъпно накланяне НЕ бива да остави колата без волан насред
// обиколката — бутоните се включват веднага.
const fallBackToButtons = () => {
    tiltError.value = true;
    controlMode.value = 'buttons';
};

const disableTilt = () => {
    window.removeEventListener('deviceorientation', onTilt);
    window.removeEventListener('orientationchange', onOrientationChange);
    tiltNeutral = null;
};

// Рекалибрира центъра на волана към текущия хват.
const recenterTilt = () => {
    tiltNeutral = null;
};
</script>

<template>
    <PublicLayout>
        <Head title="Хронометър" />

        <!-- ── Избор на писта ─────────────────────────────────────────── -->
        <div v-if="!selectedTrack" class="mx-auto max-w-5xl px-4 py-10 sm:py-14">
            <div class="mb-8">
                <h1 class="text-3xl font-black tracking-tight text-zinc-100 sm:text-4xl">
                    Хронометър<span class="text-[#e10600]">.</span>
                </h1>
                <p class="mt-3 max-w-2xl text-zinc-400">
                    Избери писта и карай чиста обиколка. Трасетата са построени от
                    реалната геометрия на пистите — всеки завой е там, където му е мястото.
                </p>

            </div>

            <div
                v-if="error"
                class="mb-6 rounded-lg border border-red-900/50 bg-red-950/40 px-4 py-3 text-sm text-red-200"
            >
                {{ error }}
            </div>

            <div v-if="tracks.length === 0" class="rounded-lg border border-zinc-800 bg-zinc-900/50 px-4 py-8 text-center text-zinc-400">
                Няма генерирани писти. Пусни
                <code class="rounded bg-zinc-800 px-1.5 py-0.5 text-zinc-300">php artisan game:generate-tracks</code>.
            </div>

            <div v-else class="grid items-start gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="track in orderedTracks"
                    :key="track.slug"
                    class="group relative overflow-hidden rounded-xl border transition"
                    :class="[
                        track.slug === weekTrack
                            ? 'border-[#e10600]/70 bg-zinc-900/80 hover:border-[#e10600]'
                            : 'border-zinc-800 bg-zinc-900/60 hover:border-[#e10600]/60',
                        loading ? 'pointer-events-none opacity-50' : '',
                    ]"
                >
                    <button
                        type="button"
                        :disabled="loading"
                        class="w-full p-5 text-left transition hover:bg-zinc-900 disabled:cursor-wait"
                        @click="startGame(track)"
                    >
                        <div
                            v-if="track.slug === weekTrack"
                            class="mb-2 inline-block rounded-full bg-[#e10600]/15 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-widest text-[#ff5a55]"
                        >
                            Пистата на уикенда
                        </div>
                        <div class="text-lg font-bold text-zinc-100 group-hover:text-white">
                            {{ track.name }}
                        </div>
                        <div class="mt-1 text-sm text-zinc-500">{{ track.location }}</div>
                        <div class="mt-4 flex items-baseline gap-4">
                            <div class="flex items-baseline gap-1.5">
                                <span class="font-mono text-2xl font-bold text-[#e10600]">
                                    {{ (track.length / 1000).toFixed(3) }}
                                </span>
                                <span class="text-xs uppercase tracking-wider text-zinc-500">км</span>
                            </div>
                            <div v-if="track.elevation > 3" class="flex items-baseline gap-1.5">
                                <span class="font-mono text-lg font-semibold text-zinc-300">
                                    {{ Math.round(track.elevation) }}
                                </span>
                                <span class="text-xs uppercase tracking-wider text-zinc-500">м денивелация</span>
                            </div>
                        </div>
                    </button>

                    <!-- Класацията на пистата: разгъва се под картата, с дуели. -->
                    <button
                        type="button"
                        class="flex w-full items-center justify-between border-t border-zinc-800/70 px-5 py-2 text-[11px] font-semibold uppercase tracking-widest text-zinc-500 transition hover:text-zinc-200"
                        @click="toggleBoard(track.slug)"
                    >
                        <span>🏆 Класация</span>
                        <span class="text-zinc-600">{{ expandedBoard === track.slug ? '▴' : '▾' }}</span>
                    </button>

                    <div v-if="expandedBoard === track.slug" class="border-t border-zinc-800/70 px-5 py-3">
                        <!-- Пистата на уикенда: седмично предизвикателство + всички времена -->
                        <div v-if="boardWeekly !== null" class="mb-2 flex gap-1.5">
                            <button
                                v-for="tab in [
                                    { v: 'week', l: 'Тази седмица' },
                                    { v: 'all', l: 'Всички времена' },
                                ]"
                                :key="tab.v"
                                type="button"
                                class="rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider transition"
                                :class="boardTab === tab.v
                                    ? 'bg-[#e10600]/20 text-[#ff5a55]'
                                    : 'bg-zinc-800/70 text-zinc-400 hover:text-zinc-200'"
                                @click="boardTab = tab.v"
                            >
                                {{ tab.l }}
                            </button>
                        </div>

                        <div v-if="boardLoading" class="py-2 text-center text-xs text-zinc-500">
                            Зареждане…
                        </div>
                        <div v-else-if="displayedBoardRows.length === 0" class="py-2 text-center text-xs text-zinc-500">
                            {{ boardTab === 'week' ? 'Никой не е карал тази седмица — бъди първи!' : 'Още няма времена — бъди първи!' }}
                        </div>
                        <ol v-else class="space-y-1.5">
                            <li
                                v-for="(row, idx) in displayedBoardRows"
                                :key="row.user_id"
                                class="flex items-center justify-between gap-2 text-sm"
                                :class="row.is_you ? 'text-fuchsia-300' : 'text-zinc-300'"
                            >
                                <span class="min-w-0 truncate">
                                    <span class="tabular-nums text-zinc-500">{{ idx + 1 }}.</span>
                                    <a
                                        :href="`/profiles/${row.user_id}`"
                                        class="transition hover:text-white hover:underline"
                                        @click.stop
                                    >{{ row.name }}</a>
                                </span>
                                <span class="flex shrink-0 items-center gap-1.5">
                                    <span class="font-mono text-xs tabular-nums">{{ formatMs(row.lap_ms) }}</span>
                                    <button
                                        v-if="row.has_ghost && !row.is_you"
                                        type="button"
                                        class="rounded bg-fuchsia-500/15 px-2 py-1 text-[11px] font-bold text-fuchsia-300 transition hover:bg-fuchsia-500/30"
                                        title="Дуел срещу духа на тази обиколка"
                                        @click="startGame(track, row.user_id)"
                                    >
                                        👻 Дуел
                                    </button>
                                    <button
                                        v-if="row.has_ghost"
                                        type="button"
                                        class="rounded bg-zinc-800 px-2 py-1 text-[11px] font-semibold text-zinc-300 transition hover:bg-zinc-700"
                                        title="Копирай линк-покана към този дуел"
                                        @click="copyChallenge(track.slug, row.user_id, `${track.slug}:${row.user_id}`)"
                                    >
                                        {{ copiedChallenge === `${track.slug}:${row.user_id}` ? '✓' : '🔗' }}
                                    </button>
                                </span>
                            </li>
                        </ol>
                    </div>
                </div>
            </div>

            <p class="mt-8 text-sm text-zinc-500">
                Управление: <kbd class="rounded bg-zinc-800 px-1.5 py-0.5 text-zinc-300">↑</kbd>
                газ, <kbd class="rounded bg-zinc-800 px-1.5 py-0.5 text-zinc-300">↓</kbd> спирачка,
                <kbd class="rounded bg-zinc-800 px-1.5 py-0.5 text-zinc-300">←</kbd>
                <kbd class="rounded bg-zinc-800 px-1.5 py-0.5 text-zinc-300">→</kbd> завиване,
                <kbd class="rounded bg-zinc-800 px-1.5 py-0.5 text-zinc-300">R</kbd> рестарт,
                <kbd class="rounded bg-zinc-800 px-1.5 py-0.5 text-zinc-300">C</kbd> бордова камера.
                Трансмисията (авто/ръчна) избираш преди всяка обиколка.
            </p>

            <!--
                Атрибуцията не е учтивост: данните за трасетата са под ODbL и
                посочването на източника е условие за ползването им.
            -->
            <div class="mt-10 border-t border-zinc-800 pt-6 text-xs leading-relaxed text-zinc-600">
                <p>
                    Трасетата са изградени от свободни географски данни: очертания и
                    ориентири © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer" class="underline hover:text-zinc-400">OpenStreetMap contributors</a>
                    (ODbL), надморска височина през
                    <a href="https://www.opentopodata.org" target="_blank" rel="noopener noreferrer" class="underline hover:text-zinc-400">OpenTopoData</a>
                    (Mapzen / Copernicus).
                </p>
                <p class="mt-2">
                    Падок не е свързан с Formula One Group, FIA или отбор. Пистите са
                    възпроизведени по географски данни, без реклами, лога и ливреи.
                </p>
            </div>
        </div>

        <!-- ── Игрови екран ───────────────────────────────────────────── -->
        <div v-else class="relative">
            <!-- dvh: на iOS Safari 100vh включва скритата toolbar лента и
                 бутонът „Спирачка" попадаше под browser chrome-а. -->
            <div class="relative h-[calc(100dvh-4rem)] min-h-[420px] w-full overflow-hidden bg-zinc-950">
                <canvas ref="canvas" class="block h-full w-full touch-none"></canvas>

                <!-- Тайминг -->
                <div class="pointer-events-none absolute left-0 top-0 p-4 sm:p-6">
                    <div class="rounded-lg bg-black/55 px-4 py-3 backdrop-blur-sm">
                        <div
                            class="text-[10px] font-semibold uppercase tracking-widest"
                            :class="telemetry.started ? 'text-zinc-400' : 'text-amber-400'"
                        >
                            {{ launchLights !== null
                                ? 'Стартова процедура'
                                : telemetry.raceTotalLaps > 0
                                    ? (telemetry.raceLap === 0
                                        ? 'Към старта'
                                        : `Обиколка ${Math.min(telemetry.raceLap, telemetry.raceTotalLaps)}/${telemetry.raceTotalLaps}`)
                                    : (telemetry.started ? 'Квалификационна обиколка' : 'Загряваща обиколка') }}
                        </div>
                        <div
                            class="font-mono text-3xl font-bold tabular-nums sm:text-4xl"
                            :class="telemetry.gated ? 'text-amber-400' : 'text-white'"
                        >
                            {{ telemetry.started ? formatLapTime(telemetry.lapTime) : '--:--.---' }}
                        </div>
                        <div
                            v-if="telemetry.gated"
                            class="text-[10px] font-semibold uppercase tracking-wider text-amber-400"
                        >
                            Върни скоростта…
                        </div>

                        <!-- Живата делта срещу духа: зелено = пред него -->
                        <div
                            v-if="telemetry.ghostDelta !== null"
                            class="font-mono text-lg font-bold tabular-nums"
                            :class="telemetry.ghostDelta <= 0 ? 'text-emerald-400' : 'text-red-400'"
                        >
                            {{ (telemetry.ghostDelta > 0 ? '+' : '') + telemetry.ghostDelta.toFixed(2) }}
                        </div>

                        <div class="mt-2 space-y-0.5 text-xs">
                            <div class="flex justify-between gap-6">
                                <span class="text-zinc-400">Най-добра</span>
                                <span class="font-mono tabular-nums text-emerald-400">
                                    {{ formatLapTime(telemetry.bestLap) }}
                                </span>
                            </div>
                            <div class="flex justify-between gap-6">
                                <span class="text-zinc-400">Последна</span>
                                <span class="font-mono tabular-nums text-zinc-200">
                                    {{ formatLapTime(telemetry.lastLap) }}
                                    <span
                                        v-if="lastLapDelta"
                                        :class="telemetry.lastLap <= telemetry.bestLap ? 'text-emerald-400' : 'text-red-400'"
                                    >
                                        {{ lastLapDelta }}
                                    </span>
                                </span>
                            </div>
                            <div v-if="telemetry.fieldSize > 1" class="flex justify-between gap-6">
                                <span class="text-zinc-400">Позиция</span>
                                <span class="font-mono tabular-nums font-bold" :class="telemetry.position === 1 ? 'text-amber-300' : 'text-zinc-200'">
                                    П{{ telemetry.position }}<span class="font-normal text-zinc-500">/{{ telemetry.fieldSize }}</span>
                                </span>
                            </div>
                        </div>

                        <div
                            v-if="!telemetry.started && telemetry.raceTotalLaps === 0 && launchLights === null"
                            class="mt-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-500"
                        >
                            Мини старта за хронометрирана обиколка
                        </div>
                        <div
                            v-else-if="!telemetry.lapValid && telemetry.fieldSize === 1"
                            class="mt-2 text-[10px] font-semibold uppercase tracking-wider text-red-400"
                        >
                            Невалидна — излизане от пистата
                        </div>
                        <div v-else class="mt-2 flex items-center gap-1.5">
                            <span class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500">Излизания</span>
                            <span
                                v-for="w in telemetry.maxWarnings"
                                :key="w"
                                class="h-2 w-2 rounded-full"
                                :class="telemetry.warnings >= w ? 'bg-red-500' : 'bg-zinc-600'"
                            ></span>
                        </div>
                    </div>

                    <div class="mt-2 flex gap-1">
                        <div
                            v-for="s in 3"
                            :key="s"
                            class="h-1 w-8 rounded-full"
                            :class="telemetry.started && telemetry.sector >= s ? 'bg-[#e10600]' : 'bg-zinc-700'"
                        ></div>
                    </div>

                    <!-- Секторни времена на последната обиколка -->
                    <div class="mt-1.5 flex gap-1.5 font-mono text-[10px] tabular-nums">
                        <span
                            v-for="(sec, i) in telemetry.sectors"
                            :key="i"
                            class="rounded bg-black/50 px-1.5 py-0.5 backdrop-blur-sm"
                        >
                            <span class="text-zinc-500">S{{ i + 1 }}</span>
                            <span class="ml-1 text-zinc-200">{{ sec === null ? '—' : sec.toFixed(3) }}</span>
                        </span>
                    </div>

                    <!-- Дуел: срещу чий дух се кара -->
                    <div
                        v-if="rivalInfo"
                        class="mt-1.5 inline-flex items-center gap-1.5 rounded bg-fuchsia-500/20 px-2 py-1 font-mono text-[11px] text-fuchsia-200 backdrop-blur-sm"
                    >
                        👻 Дуел с {{ rivalInfo.name }} · {{ formatMs(rivalInfo.lapMs) }}
                    </div>

                    <!-- Кулата с позициите (състезание): интервал до колата отпред -->
                    <div
                        v-if="telemetry.tower"
                        class="mt-2 rounded-lg bg-black/55 px-3 py-2 font-mono text-[11px] tabular-nums backdrop-blur-sm"
                    >
                        <div
                            v-for="(row, idx) in telemetry.tower"
                            :key="idx"
                            class="flex items-baseline justify-between gap-3"
                            :class="row.isPlayer ? 'font-bold text-fuchsia-300' : 'text-zinc-300'"
                        >
                            <span>
                                <span class="text-zinc-500">{{ idx + 1 }}</span>
                                {{ row.isPlayer ? 'Ти' : row.name }}
                            </span>
                            <span class="text-zinc-500">{{ idx === 0 ? '—' : `+${row.gap}м` }}</span>
                        </div>
                    </div>
                </div>

                <!-- ODbL иска източникът да се вижда там, където се вижда и картата. -->
                <div class="pointer-events-none absolute bottom-0 left-0 hidden p-2 text-[10px] text-white/35 sm:block">
                    Трасе © OpenStreetMap contributors · височини OpenTopoData
                </div>

                <!-- Скорост + предавка + оборотомер -->
                <div class="pointer-events-none absolute bottom-0 right-0 p-4 sm:p-6">
                    <div class="rounded-lg bg-black/55 px-5 py-3 backdrop-blur-sm">
                        <!-- Rev бар с shift-lights (мига в червената зона) -->
                        <div class="mb-2 flex justify-end gap-[3px]" :class="atRedline ? 'animate-pulse' : ''">
                            <span
                                v-for="(seg, i) in revSegments"
                                :key="i"
                                class="h-2 w-2 rounded-[2px]"
                                :class="seg.on ? seg.color : 'bg-zinc-700/60'"
                            ></span>
                        </div>

                        <div class="flex items-end justify-end gap-4">
                            <!-- Предавка (key-а рестартира pop анимацията при смяна) -->
                            <div class="text-center">
                                <div
                                    :key="gearLabel"
                                    class="gear-pop font-mono text-4xl font-black leading-none tabular-nums text-white sm:text-5xl"
                                >
                                    {{ gearLabel }}
                                </div>
                                <div class="text-[9px] font-semibold uppercase tracking-widest text-zinc-500">
                                    предавка
                                </div>
                            </div>
                            <!-- Скорост -->
                            <div class="text-right">
                                <div class="font-mono text-5xl font-black leading-none tabular-nums text-white sm:text-6xl">
                                    {{ telemetry.speed }}
                                </div>
                                <div class="mt-1 text-[10px] font-semibold uppercase tracking-widest text-zinc-400">
                                    км/ч
                                </div>
                            </div>
                        </div>

                        <!-- Обороти -->
                        <div class="mt-1 text-right font-mono text-[11px] tabular-nums text-zinc-400">
                            {{ (telemetry.rpm ?? 0).toLocaleString('bg-BG') }}
                            <span class="text-zinc-600">об/мин</span>
                        </div>
                    </div>
                </div>

                <!-- Мини-карта: пътят + живите точки -->
                <div class="pointer-events-none absolute right-4 top-16 hidden sm:block sm:right-6">
                    <canvas
                        ref="minimapCanvas"
                        :width="MINIMAP_SIZE"
                        :height="MINIMAP_SIZE"
                        class="rounded-lg bg-black/40 backdrop-blur-sm"
                        style="width: 128px; height: 128px"
                    ></canvas>
                </div>

                <!-- Изход / рестарт -->
                <div class="absolute right-0 top-0 flex gap-2 p-4 sm:p-6">
                    <button
                        type="button"
                        class="rounded-lg bg-black/55 px-3 py-2 text-xs font-semibold uppercase tracking-wider text-zinc-300 backdrop-blur-sm transition hover:bg-black/75 hover:text-white"
                        @click="restart"
                    >
                        Рестарт
                    </button>
                    <button
                        type="button"
                        class="rounded-lg bg-black/55 px-3 py-2 text-xs font-semibold uppercase tracking-wider text-zinc-300 backdrop-blur-sm transition hover:bg-black/75 hover:text-white"
                        @click="quit"
                    >
                        Смени пистата
                    </button>
                </div>

                <!-- Управление на телефон: накланяне ИЛИ бутони ◀ ▶ (по избор),
                     газта е авто, бутон „Спирачка". Само на тъч устройства;
                     крие се по време на ТВ реплей (там колата кара сама). -->
                <div v-if="isMobile && !replaying" class="pointer-events-none absolute inset-x-0 bottom-0 select-none p-4">
                    <div class="flex items-end justify-between gap-3">
                        <!-- Накланяне: рекалибриране на центъра. -->
                        <button
                            v-if="controlMode === 'tilt'"
                            type="button"
                            class="pointer-events-auto rounded-lg bg-black/50 px-3 py-2.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-200 backdrop-blur-sm active:bg-black/70"
                            @pointerdown.prevent="recenterTilt"
                        >
                            Центрирай волана
                        </button>
                        <!-- Бутони: ляво/дясно под левия палец. -->
                        <div v-else class="flex gap-2">
                            <button
                                type="button"
                                class="pointer-events-auto h-20 w-24 rounded-2xl bg-white/15 text-2xl font-black text-white backdrop-blur-sm active:bg-white/30"
                                @pointerdown.prevent="holdSteer(-1)"
                                @pointerup="releaseSteer(-1)"
                                @pointerleave="releaseSteer(-1)"
                                @pointercancel="releaseSteer(-1)"
                            >
                                ◀
                            </button>
                            <button
                                type="button"
                                class="pointer-events-auto h-20 w-24 rounded-2xl bg-white/15 text-2xl font-black text-white backdrop-blur-sm active:bg-white/30"
                                @pointerdown.prevent="holdSteer(1)"
                                @pointerup="releaseSteer(1)"
                                @pointerleave="releaseSteer(1)"
                                @pointercancel="releaseSteer(1)"
                            >
                                ▶
                            </button>
                        </div>
                        <button
                            type="button"
                            class="pointer-events-auto h-20 w-40 rounded-2xl bg-white/15 text-base font-bold uppercase tracking-wider text-white backdrop-blur-sm active:bg-white/30"
                            @pointerdown.prevent="holdBrake"
                            @pointerup="releaseBrake"
                            @pointerleave="releaseBrake"
                            @pointercancel="releaseBrake"
                        >
                            Спирачка
                        </button>
                    </div>
                    <p v-if="tiltError" class="mt-2 text-center text-[11px] font-semibold text-amber-400">
                        Накланянето не е достъпно — включихме екранните бутони.
                    </p>
                </div>

                <!-- ── Преди старта: избор на трансмисия + управление ────── -->
                <div
                    v-if="preStart"
                    class="absolute inset-0 z-30 flex items-center justify-center bg-black/80 p-4 backdrop-blur-sm"
                >
                    <!-- max-h + scroll: на телефон в пейзаж секциите (съперници +
                         трансмисия) надвишават височината — модалът се скролва,
                         вместо да се отреже. -->
                    <div class="max-h-full w-full max-w-md overflow-y-auto rounded-xl border border-zinc-800 bg-zinc-900/95 p-6 shadow-2xl">
                        <h2 class="text-center text-lg font-black uppercase tracking-wider text-zinc-100">
                            {{ selectedTrack?.name }}
                        </h2>
                        <p class="mt-1 text-center text-xs text-zinc-500">
                            Готви се за квалификационна обиколка
                        </p>

                        <div class="mt-5">
                            <div class="mb-2 text-[11px] font-semibold uppercase tracking-widest text-zinc-500">
                                Пистата
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <button
                                    v-for="opt in [
                                        { v: 'race', l: 'Състезание', h: `${RIVAL_COUNT} съперници на пистата` },
                                        { v: 'solo', l: 'Сам на пистата', h: 'Чиста обиколка за атака' },
                                    ]"
                                    :key="opt.v"
                                    type="button"
                                    class="rounded-lg border p-3 text-left transition"
                                    :class="[
                                        rivals === opt.v ? 'border-[#e10600] bg-[#e10600]/10' : 'border-zinc-700 hover:border-zinc-500',
                                        opt.v === 'race' && rivalInfo ? 'cursor-not-allowed opacity-40' : '',
                                    ]"
                                    :disabled="opt.v === 'race' && rivalInfo !== null"
                                    @click="rivals = opt.v"
                                >
                                    <div class="text-sm font-bold text-zinc-100">{{ opt.l }}</div>
                                    <div class="mt-0.5 text-[11px] text-zinc-400">{{ opt.h }}</div>
                                </button>
                            </div>
                            <p v-if="rivals === 'race'" class="mt-1.5 text-[11px] text-zinc-500">
                                Стартирате заедно от решетката (ти си П{{ RIVAL_COUNT + 1 }}) —
                                светлините гаснат и потегляте, с истински контакт между колите.
                                Затова времето не влиза в класацията — за рекорд карай „Сам на пистата".
                            </p>
                        </div>

                        <!-- Телефон: избор как се завива. -->
                        <div v-if="isMobile" class="mt-4">
                            <div class="mb-2 text-[11px] font-semibold uppercase tracking-widest text-zinc-500">
                                Управление
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <button
                                    v-for="opt in [
                                        { v: 'tilt', l: 'Накланяне', h: 'Върти телефона като волан' },
                                        { v: 'buttons', l: 'Бутони', h: '◀ ▶ на екрана' },
                                    ]"
                                    :key="opt.v"
                                    type="button"
                                    class="rounded-lg border p-3 text-left transition"
                                    :class="controlMode === opt.v ? 'border-[#e10600] bg-[#e10600]/10' : 'border-zinc-700 hover:border-zinc-500'"
                                    @click="controlMode = opt.v"
                                >
                                    <div class="text-sm font-bold text-zinc-100">{{ opt.l }}</div>
                                    <div class="mt-0.5 text-[11px] text-zinc-400">{{ opt.h }}</div>
                                </button>
                            </div>
                        </div>

                        <div v-if="!isMobile" class="mt-4">
                            <div class="mb-2 text-[11px] font-semibold uppercase tracking-widest text-zinc-500">
                                Трансмисия
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <button
                                    v-for="opt in [
                                        { v: 'auto', l: 'Автоматична', h: 'Играта сменя предавките' },
                                        { v: 'manual', l: 'Ръчна', h: 'W нагоре · S надолу' },
                                    ]"
                                    :key="opt.v"
                                    type="button"
                                    class="rounded-lg border p-3 text-left transition"
                                    :class="transmission === opt.v ? 'border-[#e10600] bg-[#e10600]/10' : 'border-zinc-700 hover:border-zinc-500'"
                                    @click="transmission = opt.v"
                                >
                                    <div class="text-sm font-bold text-zinc-100">{{ opt.l }}</div>
                                    <div class="mt-0.5 text-[11px] text-zinc-400">{{ opt.h }}</div>
                                </button>
                            </div>
                        </div>

                        <div class="mt-4 rounded-lg bg-black/40 p-3">
                            <div class="mb-1.5 text-[10px] font-semibold uppercase tracking-widest text-zinc-500">
                                Управление
                            </div>
                            <!-- Телефон: накланяне/бутони + авто-газ + спирачка -->
                            <div v-if="isMobile" class="space-y-1.5 text-xs text-zinc-300">
                                <div v-if="controlMode === 'tilt'">
                                    📱 <span class="font-semibold">Накланяй телефона</span> наляво/надясно, за да завиваш
                                </div>
                                <div v-else>
                                    🕹️ Завивай с бутоните <span class="font-semibold">◀ ▶</span> в долния ляв ъгъл
                                </div>
                                <div>🏎️ Газта е <span class="font-semibold">автоматична</span> — само насочваш и спираш</div>
                                <div>🛑 Задръж <span class="font-semibold">Спирачка</span>, за да намалиш за завоите</div>
                            </div>
                            <!-- Десктоп: клавиатура -->
                            <div v-else class="flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-zinc-300">
                                <span><kbd class="rounded bg-zinc-800 px-1.5 py-0.5">↑</kbd> газ</span>
                                <span><kbd class="rounded bg-zinc-800 px-1.5 py-0.5">↓</kbd> спирачка</span>
                                <span><kbd class="rounded bg-zinc-800 px-1.5 py-0.5">←</kbd> <kbd class="rounded bg-zinc-800 px-1.5 py-0.5">→</kbd> завиване</span>
                                <span v-if="transmission === 'manual'" class="font-semibold text-amber-300">
                                    <kbd class="rounded bg-zinc-800 px-1.5 py-0.5">W</kbd> нагоре ·
                                    <kbd class="rounded bg-zinc-800 px-1.5 py-0.5">S</kbd> надолу
                                </span>
                                <span><kbd class="rounded bg-zinc-800 px-1.5 py-0.5">R</kbd> рестарт</span>
                                <span><kbd class="rounded bg-zinc-800 px-1.5 py-0.5">C</kbd> камера</span>
                                <span><kbd class="rounded bg-zinc-800 px-1.5 py-0.5">M</kbd> звук</span>
                            </div>
                            <p class="mt-2 text-[11px] text-zinc-500">
                                Мини стартовата линия, за да пуснеш хронометъра.
                            </p>
                        </div>

                        <!-- Истински loading прогрес: болидът/средата по байтове -->
                        <div v-if="loading" class="mt-5">
                            <div class="h-1.5 overflow-hidden rounded-full bg-zinc-800">
                                <div
                                    class="h-full rounded-full bg-[#e10600] transition-all duration-200"
                                    :style="{ width: `${Math.round(loadProgress * 100)}%` }"
                                ></div>
                            </div>
                            <div class="mt-1.5 text-center text-[11px] text-zinc-500">
                                Зареждане… {{ Math.round(loadProgress * 100) }}%
                            </div>
                        </div>
                        <button
                            type="button"
                            class="mt-3 w-full rounded-lg bg-[#e10600] px-4 py-3 text-sm font-bold uppercase tracking-wider text-white transition hover:bg-[#ff0800] disabled:cursor-wait disabled:opacity-60"
                            :disabled="loading"
                            @click="beginLap"
                        >
                            {{ loading ? 'Зареждане…' : 'Карай' }}
                        </button>
                    </div>
                </div>

                <!-- ── Стартова процедура: петте светлини (състезание) ───── -->
                <div
                    v-if="launchLights !== null"
                    class="pointer-events-none absolute inset-x-0 top-16 z-30 flex justify-center"
                >
                    <div class="flex gap-2.5 rounded-xl bg-black/70 px-5 py-3.5 backdrop-blur-sm">
                        <span
                            v-for="n in 5"
                            :key="n"
                            class="h-5 w-5 rounded-full transition-colors duration-150"
                            :class="n <= launchLights
                                ? 'bg-red-500 shadow-[0_0_14px_rgba(239,68,68,0.9)]'
                                : 'bg-zinc-800'"
                        ></span>
                    </div>
                </div>

                <!-- ── ТВ повторение: единственият контрол на екрана ─────── -->
                <div v-if="replaying" class="absolute inset-x-0 bottom-6 z-30 flex justify-center">
                    <button
                        type="button"
                        class="rounded-full border border-zinc-600 bg-black/60 px-5 py-2.5 text-sm font-semibold text-zinc-100 backdrop-blur-sm transition hover:bg-black/80"
                        @click="stopReplay"
                    >
                        ■ Спри повторението
                    </button>
                </div>

                <!-- ── Връщане на пистата: брояч 3-2-1 ──────────────────── -->
                <!-- Скрит по време на стартовата процедура: телеметрията
                     замръзва при отброяването и старият флаг би висял отгоре. -->
                <div
                    v-if="telemetry.recovering && launchLights === null"
                    class="pointer-events-none absolute inset-0 z-30 flex flex-col items-center justify-center gap-3 bg-black/45"
                >
                    <div class="text-xs font-bold uppercase tracking-[0.3em] text-amber-300">
                        Връщане на пистата
                    </div>
                    <div class="font-mono text-8xl font-black tabular-nums text-white drop-shadow-[0_2px_12px_rgba(0,0,0,0.9)]">
                        {{ telemetry.recoverCount }}
                    </div>
                </div>

                <!-- ── Подиум: финалът на състезанието ───────────────────── -->
                <div
                    v-if="raceResult && !replaying"
                    class="absolute inset-0 z-20 flex items-center justify-center overflow-y-auto bg-black/75 p-4 backdrop-blur-sm"
                >
                    <div class="w-full max-w-md rounded-xl border border-zinc-800 bg-zinc-900/95 p-5 shadow-2xl sm:p-6">
                        <!-- Кариран флаг -->
                        <div class="mb-4 overflow-hidden rounded">
                            <svg viewBox="0 0 120 8" preserveAspectRatio="none" class="h-2 w-full">
                                <defs>
                                    <pattern id="chequer-race" width="8" height="8" patternUnits="userSpaceOnUse">
                                        <rect width="8" height="8" fill="#fafafa" />
                                        <rect width="4" height="4" fill="#0a0a0a" />
                                        <rect x="4" y="4" width="4" height="4" fill="#0a0a0a" />
                                    </pattern>
                                </defs>
                                <rect width="120" height="8" fill="url(#chequer-race)" />
                            </svg>
                        </div>

                        <div class="mb-1 flex items-center justify-center gap-2">
                            <span class="text-2xl">🏁</span>
                            <h2 class="text-lg font-black uppercase tracking-wider text-zinc-100">
                                Финал на състезанието
                            </h2>
                        </div>
                        <p class="mb-4 text-center text-xs text-zinc-500">
                            {{ selectedTrack?.name }} · {{ telemetry.raceTotalLaps }} обиколки
                        </p>

                        <div class="text-center">
                            <div
                                class="font-mono text-5xl font-black tabular-nums"
                                :class="raceResult.position === 1 ? 'text-amber-300' : 'text-white'"
                            >
                                П{{ raceResult.position }}
                            </div>
                            <div v-if="raceResult.position === 1" class="mt-1 text-sm font-bold uppercase tracking-widest text-amber-400">
                                Победа! 🏆
                            </div>
                        </div>

                        <!-- Подиумът: 2-1-3 -->
                        <div class="mt-5 flex items-end justify-center gap-2">
                            <div
                                v-for="slot in [2, 1, 3]"
                                :key="slot"
                                class="flex w-24 flex-col items-center"
                            >
                                <div
                                    class="mb-1 w-full truncate text-center text-[11px] font-semibold"
                                    :class="raceResult.standings[slot - 1]?.isPlayer ? 'text-fuchsia-300' : 'text-zinc-300'"
                                >
                                    {{ raceResult.standings[slot - 1]?.isPlayer ? 'Ти' : raceResult.standings[slot - 1]?.name }}
                                </div>
                                <div
                                    class="flex w-full items-start justify-center rounded-t font-mono text-lg font-black"
                                    :class="[
                                        slot === 1 ? 'h-16 bg-amber-400/90 text-zinc-900'
                                            : slot === 2 ? 'h-12 bg-zinc-400/90 text-zinc-900'
                                            : 'h-9 bg-amber-700/90 text-zinc-100',
                                    ]"
                                >
                                    {{ slot }}
                                </div>
                            </div>
                        </div>

                        <!-- Пълното класиране -->
                        <ol class="mt-4 space-y-1 border-t border-zinc-800 pt-3">
                            <li
                                v-for="row in raceResult.standings"
                                :key="row.position"
                                class="flex items-baseline justify-between text-sm"
                                :class="row.isPlayer ? 'font-bold text-fuchsia-300' : 'text-zinc-300'"
                            >
                                <span>
                                    <span class="tabular-nums text-zinc-500">{{ row.position }}.</span>
                                    {{ row.isPlayer ? 'Ти' : row.name }}
                                </span>
                            </li>
                        </ol>

                        <div class="mt-5 flex gap-2">
                            <button
                                type="button"
                                class="flex-1 rounded-lg bg-[#e10600] px-4 py-2.5 text-sm font-bold uppercase tracking-wider text-white transition hover:bg-[#ff0800]"
                                @click="newRace"
                            >
                                Ново състезание
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border border-zinc-700 px-4 py-2.5 text-sm font-semibold text-zinc-300 transition hover:bg-zinc-800"
                                @click="startReplay"
                            >
                                📺
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border border-zinc-700 px-4 py-2.5 text-sm font-semibold text-zinc-300 transition hover:bg-zinc-800"
                                @click="quit"
                            >
                                Смени пистата
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ── Резултат: кариран флаг + времена + класация ─────── -->
                <div
                    v-if="result && !replaying"
                    class="absolute inset-0 z-20 flex items-center justify-center overflow-y-auto bg-black/75 p-4 backdrop-blur-sm"
                >
                    <div class="w-full max-w-md rounded-xl border border-zinc-800 bg-zinc-900/95 p-5 shadow-2xl sm:p-6">
                        <!-- Кариран флаг -->
                        <div class="mb-4 overflow-hidden rounded">
                            <svg viewBox="0 0 120 8" preserveAspectRatio="none" class="h-2 w-full">
                                <defs>
                                    <pattern id="chequer" width="8" height="8" patternUnits="userSpaceOnUse">
                                        <rect width="8" height="8" fill="#fafafa" />
                                        <rect width="4" height="4" fill="#0a0a0a" />
                                        <rect x="4" y="4" width="4" height="4" fill="#0a0a0a" />
                                    </pattern>
                                </defs>
                                <rect width="120" height="8" fill="url(#chequer)" />
                            </svg>
                        </div>

                        <!-- Първо място в класацията на пистата → трофей -->
                        <div v-if="isFirstPlace" class="mb-4 flex flex-col items-center">
                            <img
                                src="/game-textures/trophy/trophy.png"
                                alt="Трофей за първо място"
                                class="h-28 w-auto drop-shadow-[0_8px_22px_rgba(234,179,8,0.5)]"
                            />
                            <div class="mt-1 text-base font-black uppercase tracking-[0.2em] text-amber-400">
                                Първо място!
                            </div>
                            <div class="text-[11px] text-zinc-400">
                                Най-бързата обиколка на пистата — от всички
                            </div>
                        </div>

                        <div class="mb-1 flex items-center justify-center gap-2">
                            <span class="text-2xl">🏁</span>
                            <h2 class="text-lg font-black uppercase tracking-wider text-zinc-100">
                                {{ result.valid ? 'Финал' : 'Край на обиколката' }}
                            </h2>
                        </div>
                        <p class="mb-4 text-center text-xs text-zinc-500">
                            {{ selectedTrack?.name }} · квалификационна обиколка
                        </p>

                        <!-- Време на обиколката -->
                        <div class="text-center">
                            <div
                                class="font-mono text-4xl font-black tabular-nums sm:text-5xl"
                                :class="lapTextClass"
                            >
                                {{ formatMs(result.lapMs) }}
                            </div>
                            <div
                                v-if="bests.lap_ms !== null"
                                class="mt-1 font-mono text-xs tabular-nums text-fuchsia-400/70"
                            >
                                Рекорд на пистата: {{ formatMs(bests.lap_ms) }}
                            </div>
                        </div>

                        <!-- Сектори -->
                        <div class="mt-4 grid grid-cols-3 gap-2">
                            <div
                                v-for="(sec, i) in result.sectorsMs"
                                :key="i"
                                class="rounded-lg border p-2 text-center"
                                :class="sectorCellClass(i)"
                            >
                                <div class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500">
                                    Сектор {{ i + 1 }}
                                </div>
                                <div
                                    class="font-mono text-base font-bold tabular-nums"
                                    :class="sectorTextClass(i)"
                                >
                                    {{ formatSectorMs(sec) }}
                                </div>
                                <div
                                    v-if="bests.sectors_ms[i] !== null"
                                    class="mt-0.5 font-mono text-[10px] tabular-nums text-fuchsia-400/70"
                                >
                                    {{ formatSectorMs(bests.sectors_ms[i]) }}
                                </div>
                            </div>
                        </div>

                        <!-- Значки: позиция / рекорд -->
                        <div
                            v-if="resultMeta"
                            class="mt-3 flex flex-wrap items-center justify-center gap-2 text-xs"
                        >
                            <span class="rounded-full bg-zinc-800 px-2.5 py-1 font-semibold text-zinc-200">
                                Позиция #{{ resultMeta.rank }}
                            </span>
                            <span
                                v-if="resultMeta.purple_lap"
                                class="rounded-full bg-fuchsia-500/20 px-2.5 py-1 font-semibold text-fuchsia-300"
                            >
                                Рекорд на пистата!
                            </span>
                            <span
                                v-else-if="resultMeta.personal_best"
                                class="rounded-full bg-emerald-500/20 px-2.5 py-1 font-semibold text-emerald-300"
                            >
                                Личен рекорд!
                            </span>
                        </div>

                        <!-- Статус на записа -->
                        <div v-if="submitting" class="mt-3 text-center text-xs text-zinc-400">
                            Записване…
                        </div>
                        <div v-if="submitError" class="mt-3 text-center text-xs text-red-400">
                            {{ submitError }}
                        </div>
                        <div
                            v-if="!result.valid"
                            class="mt-3 rounded-lg border border-amber-900/50 bg-amber-950/30 px-3 py-2 text-center text-xs text-amber-300"
                        >
                            Невалидна обиколка (излизане извън трасето) — не влиза в класацията.
                        </div>
                        <div
                            v-else-if="!authUser"
                            class="mt-3 rounded-lg border border-zinc-700 bg-zinc-800/40 px-3 py-2 text-center text-xs text-zinc-300"
                        >
                            <a href="/login" class="font-semibold text-[#e10600] hover:underline">Влез</a>,
                            за да запишеш времето си в класацията.
                        </div>

                        <!-- Класация -->
                        <div v-if="leaderboard.length" class="mt-4 border-t border-zinc-800 pt-3">
                            <div class="mb-1.5 text-[10px] font-semibold uppercase tracking-widest text-zinc-500">
                                Класация
                            </div>
                            <ol class="space-y-1">
                                <li
                                    v-for="(row, idx) in leaderboard"
                                    :key="idx"
                                    class="flex items-center justify-between gap-2 text-sm"
                                    :class="row.is_you ? 'text-fuchsia-300' : 'text-zinc-300'"
                                >
                                    <span class="min-w-0 truncate">
                                        <span class="tabular-nums text-zinc-500">{{ idx + 1 }}.</span>
                                        <a
                                            :href="`/profiles/${row.user_id}`"
                                            class="transition hover:text-white hover:underline"
                                        >{{ row.name }}</a>
                                    </span>
                                    <span class="flex shrink-0 items-center gap-1.5">
                                        <span class="font-mono tabular-nums">{{ formatMs(row.lap_ms) }}</span>
                                        <button
                                            v-if="row.has_ghost && !row.is_you"
                                            type="button"
                                            class="rounded bg-fuchsia-500/15 px-2 py-1 text-[11px] font-bold text-fuchsia-300 transition hover:bg-fuchsia-500/30"
                                            title="Дуел срещу духа на тази обиколка"
                                            @click="duelFromBoard(row)"
                                        >
                                            👻
                                        </button>
                                        <button
                                            v-if="row.has_ghost"
                                            type="button"
                                            class="rounded bg-zinc-800 px-2 py-1 text-[11px] font-semibold text-zinc-300 transition hover:bg-zinc-700"
                                            title="Копирай линк-покана към този дуел"
                                            @click="copyChallenge(selectedTrack.slug, row.user_id, `result:${row.user_id}`)"
                                        >
                                            {{ copiedChallenge === `result:${row.user_id}` ? '✓' : '🔗' }}
                                        </button>
                                    </span>
                                </li>
                            </ol>
                        </div>

                        <!-- Действия -->
                        <div class="mt-5 flex gap-2">
                            <button
                                type="button"
                                class="flex-1 rounded-lg bg-[#e10600] px-4 py-2.5 text-sm font-bold uppercase tracking-wider text-white transition hover:bg-[#ff0800]"
                                @click="newLap"
                            >
                                Нова обиколка
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border border-zinc-700 px-4 py-2.5 text-sm font-semibold text-zinc-300 transition hover:bg-zinc-800"
                                @click="startReplay"
                            >
                                📺 Повторение
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border border-zinc-700 px-4 py-2.5 text-sm font-semibold text-zinc-300 transition hover:bg-zinc-800 disabled:opacity-50"
                                :disabled="sharing"
                                title="Сподели резултата (PNG за Telegram)"
                                @click="shareResult"
                            >
                                📤
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border border-zinc-700 px-4 py-2.5 text-sm font-semibold text-zinc-300 transition hover:bg-zinc-800"
                                @click="quit"
                            >
                                Смени пистата
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </PublicLayout>
</template>

<style scoped>
/* Предавката „подскача" при смяна — key-ът в шаблона рестартира анимацията. */
@keyframes gear-pop {
    0% {
        transform: scale(1.4);
    }
    100% {
        transform: scale(1);
    }
}

.gear-pop {
    animation: gear-pop 0.16s ease-out;
}
</style>
