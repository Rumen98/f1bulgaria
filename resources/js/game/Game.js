/**
 * Оркестратор на играта: сцена, камера, вход, цикъл, хронометър.
 *
 * Времето се брои в стъпки на симулацията, НЕ по стенен часовник. Освен че е
 * коректно (кадрите се колебаят, стъпките не), това е предпоставката за
 * сървърна валидация по-късно: обиколка = брой стъпки × FIXED_DT.
 */

import * as THREE from 'three';
import { RGBELoader } from 'three/addons/loaders/RGBELoader.js';
import { Sky } from 'three/addons/objects/Sky.js';
import { EffectComposer } from 'three/addons/postprocessing/EffectComposer.js';
import { OutputPass } from 'three/addons/postprocessing/OutputPass.js';
import { RenderPass } from 'three/addons/postprocessing/RenderPass.js';
import { ShaderPass } from 'three/addons/postprocessing/ShaderPass.js';
import { SMAAPass } from 'three/addons/postprocessing/SMAAPass.js';
import { UnrealBloomPass } from 'three/addons/postprocessing/UnrealBloomPass.js';
import { driveAutopilot } from './autopilot.js';
import { buildCar, updateCarRig, attachCarModel } from './car.js';
import { circuitFor } from './circuits.js';
import { resolveCarContacts } from './collisions.js';
import { ParticleEffects } from './particles.js';
import { SkidMarks } from './skidmarks.js';
import { buildTrackMeshes, COLORS } from './mesh.js';
import { CAR, FIXED_DT, speedKmh } from './physics.js';
import {
    FRAME_EVERY,
    MAX_WARNINGS,
    SIM_VERSION,
    createSim,
    decodeFrames,
    encodeFrames,
    encodeTrace,
} from './sim.js';
import { createEngineSound } from './sound.js';
import { isMobileDevice } from './device.js';
import { prepareTrack } from './track.js';
import { createDrivetrain, shiftDown, shiftUp, updateDrivetrain } from './drivetrain.js';

/** localStorage ключ на духа (най-бързата ТИ обиколка на това устройство). */
const ghostKey = (slug) => `padok-ghost-${slug}`;

/** Максимално време, което един кадър може да добави — спира спиралата на
 *  смъртта. Свалено (0.25→0.1): след GC пауза/смяна на таб 0.25 s → ~30 стъпки в
 *  един кадър, който сам е дълъг и се самоподхранва. 0.1 = до 12 стъпки. */
const MAX_FRAME_TIME = 0.1;

/** HUD телеметрия — не по-често от 30 Hz. Vue реактивността на всеки кадър
 *  (60+ Hz) е излишен diff/patch; 30 Hz е гладко за таймера, наполовина churn. */
const TELEMETRY_INTERVAL = 1 / 30;

/** Ливреи на AI съперниците — генерични цветове, без реални отбори. */
const LIVERIES = [0x2563eb, 0xff7a00, 0x00a36c, 0xd7d7de, 0xe6007e, 0xf5c400];

/** Измислени имена на ботовете за класирането — никакви реални пилоти. */
const BOT_NAMES = ['В. Колев', 'М. Петров', 'Г. Илиев', 'Д. Стоянов', 'Н. Радев', 'Х. Марков'];

/** Дистанция на състезанието, обиколки (обиколка 1 тръгва от решетката). */
const RACE_TOTAL_LAPS = 3;

/** Стартова процедура (състезание): интервал между светлините и решетката. */
const LAUNCH_LIGHT_INTERVAL = 0.85; // s между палене на две светлини
const GRID_ROW_GAP = 7; // m между редовете на решетката
const GRID_FIRST_ROW = 6; // m от стартовата линия до първия ред
const GRID_LATERAL = 1.6; // m шахматно отместване от осевата линия

/** Веене на карирания флаг на маршала — скорост (rad/s) и амплитуда (rad). */
const FLAG_WAVE_SPEED = 8;
const FLAG_WAVE_AMP = 0.6;

const CAMERA = {
    distance: 9.5,
    height: 3.6,
    lookAhead: 12,
    /** Field of view при покой и при максимална скорост. */
    fovIdle: 62,
    fovFast: 84,
    /** Колко бързо камерата догонва колата. По-високо = по-залепена. */
    followDamping: 7.5,
    /** Бордова (halo) камера: око на пилота над кокпита. */
    onboardHeight: 1.05,
    onboardForward: 0.25,
    onboardLookAhead: 55,
};

/**
 * „Broadcast" грейд: лека наситеност, топли светли/хладни тъмни тонове и
 * мека винетка. Работи в линейно пространство, преди tone mapping-а на
 * OutputPass — един fullscreen проход, евтин и за телефон.
 */
const GRADE_SHADER = {
    uniforms: {
        tDiffuse: { value: null },
    },
    vertexShader: /* glsl */ `
        varying vec2 vUv;
        void main() {
            vUv = uv;
            gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
        }
    `,
    fragmentShader: /* glsl */ `
        uniform sampler2D tDiffuse;
        varying vec2 vUv;
        void main() {
            vec4 base = texture2D(tDiffuse, vUv);
            vec3 col = base.rgb;

            float luma = dot(col, vec3(0.2126, 0.7152, 0.0722));
            col = mix(vec3(luma), col, 1.08);
            col *= vec3(1.02, 1.0, 0.985);
            col += vec3(0.0, 0.0015, 0.004) * max(0.0, 1.0 - luma);

            float d = distance(vUv, vec2(0.5));
            col *= 1.0 - smoothstep(0.55, 0.95, d) * 0.16;

            gl_FragColor = vec4(col, base.a);
        }
    `,
};

/**
 * Груба евристика за слабо устройство (телефон / малко CPU ядра) — ползва се, за
 * да се смъкне post-processing-ът там, където fill-rate-ът е тесен. Мобилната
 * преценка е ОБЩАТА с Vue (device.js) — иначе iPad получаваше десктоп рендер
 * (composer, HDRI, 1024 сенки) с мобилни контроли.
 *
 * @returns {boolean}
 */
function isLowPowerDevice() {
    const fewCores = (navigator.hardwareConcurrency || 8) <= 4;

    return isMobileDevice() || fewCores;
}

export class Game {
    /**
     * @param {HTMLCanvasElement} canvas
     * @param {object} trackData Съдържанието на {slug}.json
     * @param {(telemetry: object) => void} onTelemetry
     * @param {(result: object) => void} [onFinish] Извиква се веднъж при
     *        завършена квалификационна обиколка (за резултатния екран).
     */
    constructor(canvas, trackData, onTelemetry, onFinish = () => {}, options = {}) {
        this.canvas = canvas;
        this.onTelemetry = onTelemetry;
        this.onFinish = onFinish;
        this.onProgress = options.onProgress ?? (() => {});
        // Една преценка за слабо устройство — ползва се на 5+ места.
        this.lowPower = isLowPowerDevice();
        // Визуалната идентичност на пистата: питлейн, терен, светлина, а вече
        // и ГЕОМЕТРИЯ — widthProfile/banking влизат в prepareTrack (circuits.js).
        this.circuit = circuitFor(trackData.slug);
        this.track = prepareTrack(trackData, this.circuit);

        this.renderer = new THREE.WebGLRenderer({
            canvas,
            antialias: true,
            powerPreference: 'high-performance',
        });
        // Над 2 нищо не се печели визуално; на телефон 1.5 е неразличимо в
        // движение, а е -44% пиксели. Отгоре работи и динамичният governor.
        this.baseDpr = Math.min(window.devicePixelRatio, this.lowPower ? 1.5 : 2);
        this.renderScale = 1;
        this.frameAvgMs = 16;
        this.scaleCooldown = 0;
        this.renderer.setPixelRatio(this.baseDpr);

        // Филмов tone mapping + сенки (Фаза 1 реализъм). Експозицията е част от
        // атмосферата на пистата (мек Спа срещу ярко крайбрежие в Зандвоорт).
        this.renderer.toneMapping = THREE.ACESFilmicToneMapping;
        this.renderer.toneMappingExposure = this.circuit.atmosphere.exposure;
        this.renderer.shadowMap.enabled = true;
        // Телефон: PCF (не Soft) — tap-овете са в пъти по-евтини, а на
        // малък екран разликата не се чете.
        this.renderer.shadowMap.type = this.lowPower ? THREE.PCFShadowMap : THREE.PCFSoftShadowMap;

        const atmosphere = this.circuit.atmosphere;
        this.scene = new THREE.Scene();
        this.scene.background = new THREE.Color(COLORS.sky); // сменя се от небето по-долу
        this.scene.fog = new THREE.Fog(atmosphere.fogColor, atmosphere.fogNear, atmosphere.fogFar);

        this.camera = new THREE.PerspectiveCamera(CAMERA.fovIdle, 1, 0.5, 2200);

        const envReady = this.#setupEnvironment();
        const trackGroup = buildTrackMeshes(this.track, this.circuit);
        this.scene.add(trackGroup);
        this.surfaceMaterials = trackGroup.userData.surfaces;
        this.marshalFlag = trackGroup.userData.marshalFlag; // вее се на летящата обиколка
        this.startLights = trackGroup.userData.startLights; // 5-те светлини на гантрито
        this.decorAnimations = trackGroup.userData.animations ?? []; // виенското колело и др.
        this.marshalPosts = trackGroup.userData.marshalPosts ?? []; // жълти флагове по постовете
        this.activeYellowPost = null;
        const trackReady = this.#loadTrackTextures();

        this.carRig = buildCar();
        this.scene.add(this.carRig.root);
        // По избор: външен GLB болид (public/game-models/car.glb). Липсва ли —
        // остава процедурният силует по-горе. Спирачното греене/ауспухът се
        // закачат СЛЕД подмяната — attachCarModel скрива децата на body-то.
        const carReady = attachCarModel(
            this.carRig,
            () => this.disposed || this.started,
            (fraction) => {
                this.loadParts.car = fraction;
                this.#reportProgress();
            }
        ).then(() => {
            this.loadParts.car = 1;
            this.#reportProgress();
            this.#buildCarLights();
        });

        // Дим/чакъл/искри + следите от гуми на играча. Размерът на частиците
        // е в буферни пиксели — казваме им реалния DPR (и после governor-а).
        this.particles = new ParticleEffects(this.scene);
        this.particles.setScale(this.baseDpr);
        this.skidMarks = new SkidMarks(this.scene);
        this.pendingImpact = null;
        this._contacts = [];

        // HDRI-то и външният болид се зареждат асинхронно. Изчакваме ги ПРЕДИ
        // старта (виж Game/Index.vue), за да не подменят вида по средата на
        // играта. Никога не reject-ва — при липса остава процедурното.
        // Прогресът тежи по реалните байтове: болидът е най-голямото сваляне.
        this.loadParts = { car: 0, env: 0, tex: 0 };
        envReady.then(() => {
            this.loadParts.env = 1;
            this.#reportProgress();
        });
        trackReady.then(() => {
            this.loadParts.tex = 1;
            this.#reportProgress();
        });
        this.ready = Promise.all([envReady, carReady, trackReady]).then(() => {
            this.#warmup();
            this.onProgress(1);
        });

        // Сенки: всичко ПРИЕМА сянка; хвърлят я колата и подбраният декор
        // близо до трасето (гантри, пит стена/гараж, гуми, табели, мостове —
        // виж castShadow в decor.js). Тежките далечни меши (земя, терен,
        // дървета, OSM сгради) не хвърлят: тяхната сянка не се вижда, а струва.
        // Сенчестият pass рисува декора всеки кадър (frustumCulled=false), но
        // общата му геометрия е десетки хиляди триъгълника — поносимо.
        this.scene.traverse((o) => {
            if (o.isMesh) {
                o.receiveShadow = true;
                // Телефон: декорът НЕ хвърля сянка (цял geometry pass по-малко);
                // колите си я пазят — тяхната е тази, която „стъпва" на пътя.
                if (this.lowPower) {
                    o.castShadow = false;
                }
            }
        });
        this.carRig.root.traverse((o) => {
            if (o.isMesh) {
                o.castShadow = true;
            }
        });

        // Цялата постъпкова логика (повърхности, физика, хронометър, запис на
        // входа) живее в sim.js — същият код тича и в сървърната валидация.
        this.sim = createSim(this.track, this.circuit);

        this.input = { throttle: 0, brake: 0, steer: 0 };
        this.keys = new Set();
        this.touch = { throttle: 0, brake: 0, steer: 0 };
        // Авто-газ (мобилно): болидът ускорява сам, играчът само насочва (tilt) и
        // спира. Включва се от Vue при мобилно устройство.
        this.autoThrottle = false;

        this.effectTime = 0;
        // Шейкът от миналия кадър — вади се преди изглаждането на камерата,
        // за да не влиза в персистентното ѝ състояние (иначе амплитудата
        // зависи от кадровата честота).
        this.cameraShakeOffset = 0;

        // Камера: chase (по подразбиране) или бордова (C). Halo силуетът е
        // дете на камерата — видим само в бордовия режим.
        this.cameraMode = 'chase';
        this.scene.add(this.camera);
        this.halo = buildHaloOverlay();
        this.halo.visible = false;
        this.camera.add(this.halo);
        // Воланът в бордовата камера — върти се със state.steer.
        this.steeringWheel = buildSteeringWheel();
        this.halo.add(this.steeringWheel);

        // G-force наклоните на бордовата камера (изгладени ускорения).
        this.gLong = 0;
        this.gLat = 0;
        this.prevRenderV = 0;

        // Звукът: синтезиран двигател (sound.js). Контекстът се създава чак
        // при start() — бутонът „Карай" е потребителският жест.
        this.sound = createEngineSound();

        // Vue-то закача този callback, за да маха replay overlay-а, когато
        // реплеят свърши отвътре (R рестарт/reset), не само от своя бутон.
        this.onReplayEnd = () => {};

        // Духът: най-бързата обиколка на това устройство, полупрозрачен болид,
        // каращ редом с теб на летящата обиколка. Реплеят ползва същите кадри.
        // Без личен рекорд се зарежда ОФИЦИАЛНИЯТ дух на Падок (златист) —
        // и първият играч на пистата има срещу кого да кара.
        this.ghost = this.#loadGhost();
        this.ghostRig = buildGhostRig();
        this.ghostRig.root.visible = false;
        this.scene.add(this.ghostRig.root);
        if (!this.ghost) {
            this.#loadOfficialGhost();
        }
        this.lastLapFrames = null; // кадрите на току-що завършената обиколка
        this.replay = null; // {frames, t, camIndex} — активен ТВ реплей

        // AI съперници („състезание"): всеки със собствена детерминирана
        // симулация + автопилот. НЕ пипат физиката на играча — виж setOpponents.
        this.opponents = [];
        // Място в „състезанието" (позиция П1..Пn): цели обиколки + прогрес,
        // следи се и за играча.
        this.playerRace = { laps: 0, lastProgress: 0 };
        // Стартова процедура: {elapsed, hold} докато тече отброяването със
        // светлините — симулацията е замразена, никой не потегля преди гасене.
        this.launch = null;
        // Vue-то показва светлините през този callback (брой светнали, null = край).
        this.onLaunch = () => {};

        // Финал на състезанието: {position, standings} след RACE_TOTAL_LAPS.
        this.raceResult = null;
        this.onRaceFinish = () => {};

        // Соло резултатният екран пада симетрично на подиума: вътрешен reset
        // (R / „Рестарт" по време на реплей) чисти и Vue състоянието през това.
        this.onResultClear = () => {};

        // Дуел: духът на съперник от класацията (сървърни кадри). Докато е
        // зареден, се показва ТОЙ (фуксия), а не личният/официалният.
        this.rivalGhost = null;

        // Живата делта срещу духа: указател в кадрите му (локално търсене).
        this.ghostDeltaHint = 0;

        // Доплер на съперника: последна дистанция/време за радиалната скорост.
        this.prevRivalDistance = null;
        this.prevRivalTime = 0;

        // Attract: духът кара демо зад pre-start екрана, докато чакаш „Карай".
        this.attractId = null;

        // Мини-картата: нормализирани точки на трасето (веднъж) за Vue canvas.
        this.minimap = buildMinimap(this.track);

        this.accumulator = 0;
        this.lastFrame = 0;
        this.running = false;
        this.rafId = null;
        // Пазят инвариантите на асинхронните loader-и: не подменяй вида СЛЕД
        // старта (късен pop) и не пипай renderer-а СЛЕД освобождаване.
        this.started = false;
        this.disposed = false;

        // Преизползвани обекти (нула алокации/кадър в hot path) + акумулатори.
        this._render = {};
        this._contactCars = [];
        this.telemetryAccum = TELEMETRY_INTERVAL; // първи кадър праща телеметрия веднага
        this.flagWave = 0;

        // Трансмисия (обороти/предавка за HUD).
        this.manualTransmission = options.transmission === 'manual';
        this.drivetrain = createDrivetrain(this.manualTransmission);

        this.#placeCameraBehindCar();
        this.#bindEvents();
        this.#setupComposer();
        this.resize();
    }

    /** Стартира цикъла. */
    start() {
        if (this.running) {
            return;
        }

        this.stopAttract();
        this.running = true;
        this.started = true;
        this.lastFrame = performance.now();
        this.playerRace = { laps: 0, lastProgress: this.sim.lastProgress };
        this.sound.start();
        this.onLaunch(this.launch ? 0 : null);
        this.rafId = requestAnimationFrame(this.#frame);
    }

    /**
     * Attract режим: духът (официалният или дуелният) кара ТВ демо зад
     * pre-start екрана. Спира се сам при start()/dispose.
     */
    startAttract() {
        if (this.attractId !== null || this.running || this.disposed) {
            return;
        }

        const ghost = this.rivalGhost ?? this.ghost;
        if (!ghost?.frames || ghost.frames.length < 6) {
            return;
        }

        this.replay = { frames: ghost.frames, t: 0, camIndex: -1 };
        this.lastFrame = performance.now();

        const loop = (now) => {
            if (this.running || this.disposed || this.replay === null) {
                this.attractId = null;
                return;
            }
            this.attractId = requestAnimationFrame(loop);
            const dt = Math.min((now - this.lastFrame) / 1000, MAX_FRAME_TIME);
            this.lastFrame = now;
            this.#replayFrame(dt);
        };
        this.attractId = requestAnimationFrame(loop);
    }

    stopAttract() {
        if (this.attractId === null) {
            return;
        }
        cancelAnimationFrame(this.attractId);
        this.attractId = null;
        this.replay = null;
        this.#placeCameraBehindCar();
    }

    /** Спира цикъла, без да освобождава ресурси. */
    stop() {
        this.running = false;
        this.sound.stop();
        if (this.rafId !== null) {
            cancelAnimationFrame(this.rafId);
            this.rafId = null;
        }
    }

    /**
     * Връща колата на стартовата линия.
     *
     * @param {boolean} keepRecords Дали рекордът да се запази
     */
    reset(keepRecords = true) {
        this.stopReplay();
        this.sim.reset(keepRecords);
        this.accumulator = 0;
        // Нова обиколка = ново състезание: решетка + светлини отначало.
        // Vue-то сваля подиума през същия callback (R по време на подиум).
        this.raceResult = null;
        this.onRaceFinish(null);
        this.onResultClear();
        this.#gridOpponents();
        this.#gridPlayer();
        this.#armLaunch();
        this.playerRace = { laps: 0, lastProgress: this.sim.lastProgress };
        this.#placeCameraBehindCar();
    }

    /**
     * Зарежда духа на съперник от класацията (сървърните кадри от
     * потвърдената му обиколка) — дуелът „Карай срещу…".
     *
     * @param {{frames: string, lap_ticks: number|null, lap_ms: number, name: string}} data
     * @returns {boolean}
     */
    setRivalGhost(data) {
        const frames = decodeFrames(data.frames);
        if (!frames || frames.length < 6) {
            return false;
        }

        this.rivalGhost = {
            frames,
            // lap_ticks липсва при стари записи — извежда се от кадрите.
            lapTicks: data.lap_ticks ?? Math.floor(frames.length / 3) * FRAME_EVERY,
            name: data.name,
        };
        tintGhostRig(this.ghostRig, 0xe879f9); // фуксия = съперник от класацията

        // Демото зад pre-start екрана превключва на дуелния дух.
        if (!this.running) {
            this.stopAttract();
            this.startAttract();
        }

        return true;
    }

    /**
     * Конфигурира AI съперниците (вика се от pre-start екрана, преди start()).
     *
     * В състезание колите СЕ БЛЪСКАТ (collisions.js) — и играчът. Именно
     * затова състезателните времена не отиват в класацията: сървърният
     * реплей не може да възпроизведе чужди удари. Класацията се кара „Сам
     * на пистата", където физиката на играча е чиста функция от входа му.
     *
     * @param {number} count 0 = сам на пистата
     */
    setOpponents(count) {
        this.#clearOpponents();

        if (!count) {
            return;
        }

        // Детерминирано по пистата — една и съща решетка при всеки рестарт.
        const rand = mulberry32(hashString(this.track.slug));

        // Геометрията на болида е идентична за всички ботове — първият риг я
        // дава на останалите (5× по-малко GPU буфери). Материалите остават
        // per-кола (ливрея + изсветляване).
        let templateGeometries = null;

        for (let i = 0; i < count; i++) {
            // Ботът дели готовите повърхностни таблици на играча (същата
            // писта) — без 5 повторни скана на кривината при „Карай".
            const sim = createSim(this.track, this.circuit, this.sim);
            // Обиколките на ботовете не интересуват никого — без запис и без
            // наказателен телепорт на старта (само локалното връщане).
            sim.recordEnabled = false;

            const rig = buildOpponentRig(LIVERIES[i % LIVERIES.length]);
            if (templateGeometries === null) {
                templateGeometries = [];
                rig.root.traverse((object) => {
                    if (object.isMesh) {
                        templateGeometries.push(object.geometry);
                    }
                });
            } else {
                // buildCar е детерминиран → редът на обхождане съвпада 1:1.
                let next = 0;
                rig.root.traverse((object) => {
                    if (object.isMesh) {
                        object.geometry.dispose();
                        object.geometry = templateGeometries[next++];
                    }
                });
            }
            this.scene.add(rig.root);

            this.opponents.push({
                sim,
                rig,
                input: { steer: 0, throttle: 0, brake: 0 },
                // Разлики в темпото/линията — полето да не кара в индийска нишка.
                pace: 0.9 + rand() * 0.22,
                steerGain: 2.65 + rand() * 0.35,
                lookBias: (rand() - 0.5) * 6,
                // Малка лична вариация ВЪРХУ състезателната линия (raceOffset).
                lineOffset: (rand() - 0.5) * 1.6,
                slotJitter: rand() * 0.5,
                laps: 0,
                lastProgress: 0,
                prevX: 0,
                prevZ: 0,
                prevHeading: 0,
                _render: {},
            });
        }

        // Кой кого вижда (за избягването): СИМУЛАЦИИТЕ са стабилни обекти
        // (reset мутира state на място, не го подменя), затова референциите
        // остават живи и след престрояване; recovering се чете от самата сим.
        for (const opp of this.opponents) {
            opp.others = [
                this.sim,
                ...this.opponents.filter((o) => o !== opp).map((o) => o.sim),
            ];
        }

        this.#gridOpponents();
        this.#gridPlayer();
        this.#armLaunch();
    }

    /**
     * ТВ реплей на последната завършена обиколка: колата повтаря кадрите,
     * камерата скача между крайпътни постове като телевизионна режисура.
     *
     * @returns {boolean} Дали има какво да се повтори
     */
    startReplay() {
        if (!this.lastLapFrames || this.lastLapFrames.length < 6) {
            return false;
        }

        this.replay = { frames: this.lastLapFrames, t: 0, camIndex: -1 };
        this.ghostRig.root.visible = false;
        // ТВ картина: без halo и без двигател в ухото. Съперниците се крият —
        // записът е само на играча, а замразени в кадъра биха изглеждали
        // катастрофирали.
        for (const opp of this.opponents) {
            opp.rig.root.visible = false;
        }
        this.halo.visible = false;
        this.sound.stop();

        return true;
    }

    /** Изход от реплея — обратно към резултатния екран/колата. */
    stopReplay() {
        if (!this.replay) {
            return;
        }
        this.replay = null;
        this.lookTarget = null;
        this.halo.visible = this.cameraMode === 'onboard';
        for (const opp of this.opponents) {
            opp.rig.root.visible = true;
        }
        if (this.running) {
            this.sound.start();
        }
        this.#placeCameraBehindCar();
        // Vue-то маха своя replay overlay през този callback.
        this.onReplayEnd();
    }

    /** Преоразмерява рендера към текущия размер на canvas-а. */
    resize() {
        const width = this.canvas.clientWidth || 1;
        const height = this.canvas.clientHeight || 1;

        this.renderer.setSize(width, height, false);
        this.camera.aspect = width / height;
        this.camera.updateProjectionMatrix();

        this.composer?.setSize(width, height);
    }

    /**
     * Управление от екранните бутони.
     *
     * @param {{throttle?: number, brake?: number, steer?: number}} values
     */
    setTouchInput(values) {
        Object.assign(this.touch, values);
    }

    /** Задава трансмисията (авто/ръчна). Вика се ПРЕДИ старта (pre-start екран). */
    setTransmission(mode) {
        this.manualTransmission = mode === 'manual';
        this.drivetrain.manual = this.manualTransmission;
    }

    /** Освобождава WebGL ресурсите. Задължително при unmount. */
    dispose() {
        this.disposed = true;
        this.stop();
        this.sound.dispose();
        this.particles.dispose();
        this.skidMarks.dispose();
        this.#unbindEvents();

        this.scene.traverse((object) => {
            if (object.geometry) {
                object.geometry.dispose();
            }

            if (object.material) {
                const materials = Array.isArray(object.material)
                    ? object.material
                    : [object.material];

                for (const material of materials) {
                    // material.dispose() НЕ чисти картите — освобождаваме ги ръчно,
                    // иначе canvas текстурите (публика/бордове/флаг) и текстурите на
                    // GLB болида текат GPU памет при всеки quit/restart.
                    for (const key of ['map', 'normalMap', 'roughnessMap', 'metalnessMap', 'aoMap', 'emissiveMap']) {
                        material[key]?.dispose?.();
                    }
                    material.dispose();
                }
            }

            // geometry.dispose() НЕ чисти instanceMatrix буфера — трибуните,
            // ориентирите и дърветата са InstancedMesh; освобождаваме ги изрично.
            if (object.isInstancedMesh) {
                object.dispose();
            }
        });

        // EffectComposer.dispose() не чисти passes-ите — Bloom/SMAA/Output си
        // държат собствени render targets, които иначе текат при всеки quit.
        for (const pass of this.composer?.passes ?? []) {
            pass.dispose?.();
        }
        this.composer?.dispose();
        this.cubeRT?.dispose();
        this.envRT?.dispose();
        this.hdrBackground?.dispose();
        // Shadow map-ът на слънцето е отделен render target — нито traverse-ът,
        // нито renderer.dispose() го чистят (~4 MB GPU на рестарт).
        this.sun?.shadow?.dispose?.();
        this.renderer.dispose();
    }

    // ── Вътрешни ─────────────────────────────────────────────────────────

    /**
     * Зарежда tiling PBR текстурите на пистата (асфалт). Обектите се връщат
     * веднага (пълнят се при decode), а промисът се резолвва при зареждане —
     * добавя се към this.ready, за да са готови ПРЕДИ първия кадър (без pop).
     *
     * @returns {Promise<void>}
     */
    #loadTrackTextures() {
        const loader = new THREE.TextureLoader();
        const maxAniso = this.renderer.capabilities.getMaxAnisotropy?.() ?? 1;

        // Зарежда една карта; резолвва с текстурата при успех или с null при
        // грешка (никога reject).
        const load = (url, srgb, repeat) => new Promise((resolve) => {
            const texture = loader.load(url, () => resolve(texture), undefined, () => resolve(null));
            texture.wrapS = THREE.RepeatWrapping;
            texture.wrapT = THREE.RepeatWrapping;
            texture.anisotropy = maxAniso;
            texture.repeat.set(repeat[0], repeat[1]);
            if (srgb) {
                texture.colorSpace = THREE.SRGBColorSpace;
            }
            return texture;
        });

        // Подменя процедурния материал на повърхността САМО при успешен diffuse
        // и само ако играта още не е тръгнала/освободена — иначе остава
        // процедурният цвят (без черно, без късен pop). repeat: u напречно (по
        // ширината), v по дължина на всеки 8 m (виж UV-то в ribbonMesh).
        // Телефон: само diffuse — normal/rough картите (≈4.3 MB) не се четат
        // на малък екран под движеща се камера, а тройният texture fetch на
        // фрагмент яде точно тесния мобилен bandwidth.
        const detail = !this.lowPower;
        const applyTo = (name, dir, repeat) => Promise.all([
            load(`/game-textures/${dir}/diff.jpg`, true, repeat),
            detail ? load(`/game-textures/${dir}/nor.jpg`, false, repeat) : Promise.resolve(null),
            detail ? load(`/game-textures/${dir}/rough.jpg`, false, repeat) : Promise.resolve(null),
        ]).then(([map, normalMap, roughnessMap]) => {
            const material = this.surfaceMaterials?.[name];
            if (!map || this.started || this.disposed || !material) {
                for (const texture of [map, normalMap, roughnessMap]) {
                    texture?.dispose?.();
                }
                return;
            }
            // Чакълът тръгва с процедурна canvas карта — освобождаваме я, преди
            // да я подменим, иначе стои в GPU паметта до края на сесията.
            material.map?.dispose?.();
            material.map = map;
            material.normalMap = normalMap;
            material.roughnessMap = roughnessMap;
            material.vertexColors = false;
            // Тревата се тонира според пистата (изсушена в Зандвоорт, златиста
            // в Монца) — текстурата е обща, характерът идва от тона.
            material.color.set(name === 'grass' ? this.circuit.grassTint : 0xffffff);
            material.needsUpdate = true;
        });

        // Бавна мрежа да не държи loading екрана безкрайно.
        return Promise.race([
            Promise.all([
                applyTo('asphalt', 'asphalt', [6, 4]),
                applyTo('grass', 'grass', [8, 3]),
                applyTo('gravel', 'gravel', [2, 2]),
            ]),
            new Promise((resolve) => setTimeout(resolve, 6000)),
        ]);
    }

    #setupEnvironment() {
        // Посока на слънцето от азимут/елевация — част от атмосферата на
        // пистата (ниското златно слънце на Монца, високото на Монако).
        const atmosphere = this.circuit.atmosphere;
        const night = atmosphere.night === true;
        const elevation = atmosphere.sunElevation;
        const azimuth = atmosphere.sunAzimuth;
        const phi = THREE.MathUtils.degToRad(90 - elevation);
        const theta = THREE.MathUtils.degToRad(azimuth);
        const sunDir = new THREE.Vector3().setFromSphericalCoords(1, phi, theta);

        // Нощ: „слънцето" на НЕБЕСНИЯ шейдър отива под хоризонта (-12°) —
        // Rayleigh моделът сам дава тъмносиния здрач; directional-ът горе
        // остава конфигурираният (сборният ефект на прожекторите).
        const skyPhi = night ? THREE.MathUtils.degToRad(90 + 12) : phi;
        const skySunDir = new THREE.Vector3().setFromSphericalCoords(1, skyPhi, theta);

        // Атмосферно небе (Rayleigh/Mie). Рендираме го в кубмап → фон (винаги на
        // хоризонта, независимо от позицията) + environment map за IBL.
        const sky = new Sky();
        sky.scale.setScalar(10000);
        const u = sky.material.uniforms;
        u.turbidity.value = 6;
        u.rayleigh.value = 2.2;
        u.mieCoefficient.value = 0.005;
        u.mieDirectionalG.value = 0.8;
        u.sunPosition.value.copy(skySunDir);

        const cubeRT = new THREE.WebGLCubeRenderTarget(256);
        const cubeCam = new THREE.CubeCamera(1, 200000, cubeRT);
        const skyScene = new THREE.Scene();
        skyScene.add(sky);
        cubeCam.update(this.renderer, skyScene);
        this.scene.background = cubeRT.texture;

        const pmrem = new THREE.PMREMGenerator(this.renderer);
        this.envRT = pmrem.fromCubemap(cubeRT.texture);
        this.scene.environment = this.envRT.texture;
        // Същата сила като при HDRI-то → няма скок в осветлението, ако HDRI-то
        // се приложи по-късно или изобщо липсва. Нощем env-ът е блед здрач.
        this.scene.environmentIntensity = night ? 0.25 : 0.6;
        this.cubeRT = cubeRT;

        // Звезди над нощните писти — статичен Points купол. Радиусът стои ПОД
        // far плана на камерата (2200): точка извън клип обема се реже изцяло
        // от GPU-то (frustumCulled=false спира само CPU cull-а). Seed-ът е
        // детерминиран по пистата — небето не се разбърква при рестарт.
        if (night) {
            const starCount = 450;
            const positions = new Float32Array(starCount * 3);
            const rand = mulberry32(hashString(this.track.slug));
            for (let i = 0; i < starCount; i++) {
                // Горна полусфера, равномерно по площ.
                const azimuthAngle = rand() * Math.PI * 2;
                const y = 0.12 + rand() * 0.88;
                const r = Math.sqrt(1 - y * y);
                positions[i * 3] = Math.cos(azimuthAngle) * r * 1800;
                positions[i * 3 + 1] = y * 1800;
                positions[i * 3 + 2] = Math.sin(azimuthAngle) * r * 1800;
            }
            const starGeometry = new THREE.BufferGeometry();
            starGeometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
            const stars = new THREE.Points(
                starGeometry,
                new THREE.PointsMaterial({
                    color: 0xdfe8ff,
                    size: 2.0,
                    sizeAttenuation: false,
                    transparent: true,
                    opacity: 0.8,
                    depthWrite: false,
                    fog: false,
                })
            );
            stars.frustumCulled = false;
            this.scene.add(stars);
            // Куполът следва камерата в #render (skybox трик): пистите не са
            // центрирани в origin-а (Джеда стига ~1.4 km встрани) и статичен
            // купол пак би излязъл извън far плана на отсрещния ръб.
            this.stars = stars;
        }
        pmrem.dispose();
        sky.geometry.dispose();
        sky.material.dispose();

        // Мека околна светлина + ключова слънчева със сенки.
        // Намалена — HDRI-то вече дава небесен fill, затова аналитичната е
        // по-слаба. Нощем fill-ът е студен и слаб (небето не свети).
        this.scene.add(
            night
                ? new THREE.HemisphereLight(0x27324a, 0x0b0d12, 0.5)
                : new THREE.HemisphereLight(0xbfd8ff, 0x33402f, 0.75)
        );

        const sun = new THREE.DirectionalLight(atmosphere.sunColor, atmosphere.sunIntensity);
        sun.castShadow = true;
        // Телефон: 512 върху 60 m кутия е пак ~8.5 texel/m — контактната
        // сянка под колата остава, цената пада 4×.
        const shadowSize = this.lowPower ? 512 : 1024;
        sun.shadow.mapSize.set(shadowSize, shadowSize);
        sun.shadow.bias = -0.0004;
        sun.shadow.normalBias = 0.6;
        // Само болидът хвърля сянка (виж конструктора) и сенчестата камера следва
        // колата — затова стягаме кутията до ~60 m около нея. 1024 върху 60 m е
        // остро (~17 texel/m), докато старите 170 m разпиляваха картата по празен
        // терен. По-малка кутия = по-остра сянка И по-евтино.
        const s = 30; // половин размер на сенчестата зона около колата, m
        Object.assign(sun.shadow.camera, { left: -s, right: s, top: s, bottom: -s, near: 1, far: 900 });
        sun.shadow.camera.updateProjectionMatrix();
        this.scene.add(sun);
        this.scene.add(sun.target);

        this.sun = sun;
        this.sunDir = sunDir;

        // ── Фаза 2: истински HDRI за околната среда ──────────────────────────
        // Отраженията по clearcoat боята и по мокрия асфалт идват от снимано
        // небе (Poly Haven CC0), не от процедурното — оттам „реалният" вид.
        //
        // Процедурното небе горе е само мигновен placeholder. HDRI-то се
        // ЗАРЕЖДА ПРЕДИ старта (Game.start го чака през this.ready), за да не
        // подменя фон/светлина по средата на играта („смяна на климата").
        // PMREM се смята веднъж → нулев per-frame разход. Никога не reject-ва:
        // при липсващ файл или бавна мрежа остава процедурното небе.
        // Нощем HDRI (дневно небе) няма работа — процедурният здрач остава.
        // На телефон — също: 4.5 MB заради отражения, нечетими на 6" екран.
        if (night || this.lowPower) {
            return Promise.resolve();
        }

        return new Promise((resolve) => {
            let settled = false;
            const done = () => {
                if (settled) {
                    return;
                }
                settled = true;
                clearTimeout(timer);
                resolve();
            };
            // Бавна мрежа да не държи loading екрана безкрайно.
            const timer = setTimeout(done, 6000);

            new RGBELoader().load(
                // Небето е част от идентичността: мрачното на Спа не е това на
                // Монако. Файлът идва от atmosphere.hdri (по подразбиране общото).
                `/game-hdri/${atmosphere.hdri ?? 'sky_2k'}.hdr`,
                (hdr) => {
                    // Късно (след timeout/старт) или след освобождаване — не
                    // подменяй фона (би било pop) и не пускай PMREM на мъртъв renderer.
                    if (this.started || this.disposed) {
                        hdr.dispose?.();
                        done();
                        return;
                    }
                    hdr.mapping = THREE.EquirectangularReflectionMapping;
                    const pmrem = new THREE.PMREMGenerator(this.renderer);
                    const envRT = pmrem.fromEquirectangular(hdr);
                    pmrem.dispose();

                    this.envRT?.dispose?.();       // старият env (от процедурното небе)
                    this.envRT = envRT;
                    this.scene.environment = envRT.texture;
                    this.scene.environmentIntensity = 0.6;
                    this.scene.background = hdr;    // видимото небе = HDRI → отраженията съвпадат с гледката
                    this.hdrBackground = hdr;       // пазим за dispose при teardown
                    done();
                },
                undefined,
                done,   // няма HDRI → остава процедурното небе
            );
        });
    }

    #setupComposer() {
        // Телефон: БЕЗ composer изобщо. Директният renderer.render прилага
        // ACES + sRGB нативно, а antialias:true на контекста дава хардуерен
        // MSAA — почти безплатен на мобилните tile GPU-та. Плащахме 5+
        // fullscreen прохода за grade/SMAA, които на 6" не се четат.
        if (this.lowPower) {
            this.composer = null;
            return;
        }

        const w = this.canvas.clientWidth || 1;
        const h = this.canvas.clientHeight || 1;

        this.composer = new EffectComposer(this.renderer);
        this.composer.addPass(new RenderPass(this.scene, this.camera));

        // Само ярките акценти греят: слънчеви отблясъци по clearcoat боята,
        // прожекторите/стартовите светлини нощем. Висок threshold.
        this.composer.addPass(new UnrealBloomPass(new THREE.Vector2(w, h), 0.1, 0.5, 1.0));

        // Broadcast грейд — един евтин fullscreen проход.
        this.composer.addPass(new ShaderPass(GRADE_SHADER));

        // SMAA — composer-ът рендерира в offscreen target, така че
        // antialias:true на контекста не важи по този път.
        this.composer.addPass(new SMAAPass());

        // Финал: tone mapping (ACES от рендера) + sRGB към екрана. При composer
        // рендерът е линеен до OutputPass, затова няма двойно tone mapping.
        this.composer.addPass(new OutputPass());
    }

    /** Loading прогрес към Vue: болидът е ~70% от реалните байтове. */
    #reportProgress() {
        this.onProgress(
            Math.min(0.99, this.loadParts.car * 0.7 + this.loadParts.env * 0.15 + this.loadParts.tex * 0.15)
        );
    }

    /**
     * Governor на резолюцията: пълзяща средна на кадъра; над ~22 ms сваля
     * мащаба със стъпка (бързо надолу), под ~13 ms бавно го връща. HUD-ът е
     * DOM и остава кристален независимо от 3D резолюцията.
     *
     * @param {number} rawDt Секунди, преди MAX_FRAME_TIME клампата
     */
    #governResolution(rawDt) {
        // Връщане от скрит таб дава rawDt от секунди/минути — това е пауза,
        // не бавен кадър, и се игнорира изцяло: дори клампната ѝ стойност би
        // вдигнала EMA-то над прага и би струвала стъпка надолу на здраво
        // устройство. Реални бавни кадри (thermal) са 30-60 ms, не >250 ms.
        if (rawDt > 0.25) {
            return;
        }
        this.frameAvgMs += (rawDt * 1000 - this.frameAvgMs) * 0.05;
        this.scaleCooldown -= rawDt;

        if (this.scaleCooldown > 0) {
            return;
        }

        if (this.frameAvgMs > 22 && this.renderScale > 0.55) {
            this.renderScale = Math.max(0.55, this.renderScale - 0.15);
            this.scaleCooldown = 1.0;
            this.#applyRenderScale();
        } else if (this.frameAvgMs < 13 && this.renderScale < 1) {
            this.renderScale = Math.min(1, this.renderScale + 0.15);
            this.scaleCooldown = 3.0;
            this.#applyRenderScale();
        }
    }

    #applyRenderScale() {
        this.renderer.setPixelRatio(this.baseDpr * this.renderScale);
        // EffectComposer кешира pixel ratio-то при конструкция — без изричния
        // setPixelRatio неговите render targets (RenderPass/Bloom/SMAA, т.е.
        // основната GPU цена) остават на пълна резолюция и governor-ът само
        // замъглява картината, без да печели кадри.
        this.composer?.setPixelRatio(this.baseDpr * this.renderScale);
        // Частиците са оразмерени в буферни пиксели — подаваме новия мащаб,
        // за да не подскачат спрямо колата при стъпка на governor-а.
        this.particles.setScale(this.baseDpr * this.renderScale);
        this.resize();
    }

    /** Рендер през composer-а (десктоп) или директно (телефон). */
    #render() {
        // Звездите (нощ) висят на фиксиран радиус ОКОЛО камерата — така
        // никога не опират far плана, а без паралакс изглеждат безкрайно далеч.
        this.stars?.position.copy(this.camera.position);
        if (this.composer) {
            this.composer.render();
        } else {
            this.renderer.render(this.scene, this.camera);
        }
    }

    /**
     * Компилира шейдърите и post-processing passes ПРЕДИ старта, докато loading
     * екранът е още горе (this.ready ги чака). Иначе първият composer.render()
     * блокира главната нишка за 100–500ms — clearcoat/сенки/bloom/SMAA се
     * компилират лениво при първото рисуване, точно щом играчът очаква да тръгне.
     */
    #warmup() {
        if (this.disposed) {
            return;
        }
        this.renderer.compile(this.scene, this.camera);
        // renderer.compile не топли post passes-ите — трябват реални кадри.
        this.#render();
        this.#render();
    }

    /**
     * Духът от localStorage: {frames, lapTicks} или null.
     */
    #loadGhost() {
        try {
            const raw = localStorage.getItem(ghostKey(this.track.slug));
            if (!raw) {
                return null;
            }
            const parsed = JSON.parse(raw);
            if (parsed.v !== SIM_VERSION) {
                return null; // стар запис от друга физика — не е честен съперник
            }
            const frames = decodeFrames(parsed.frames);
            return frames ? { frames, lapTicks: parsed.lapTicks } : null;
        } catch {
            return null;
        }
    }

    /**
     * Официалният дух на Падок (public/game-ghosts, scripts/game/build-ghosts.mjs):
     * еталонна обиколка на автопилота — показва се, докато нямаш собствена.
     */
    async #loadOfficialGhost() {
        try {
            const response = await fetch(`/game-ghosts/${this.track.slug}.json`);
            if (!response.ok) {
                return;
            }
            const parsed = await response.json();
            // Междувременно играчът може да е направил своя обиколка — тя печели.
            if (parsed.v !== SIM_VERSION || this.disposed || this.ghost) {
                return;
            }
            const frames = decodeFrames(parsed.frames);
            if (!frames) {
                return;
            }
            this.ghost = { frames, lapTicks: parsed.lapTicks, official: true };
            if (!this.rivalGhost) {
                tintGhostRig(this.ghostRig, 0xf2c14e); // златист = официалният
            }
            // Ако pre-start екранът още стои — духът тръгва като демо.
            this.startAttract();
        } catch {
            // Няма официален дух за тази писта — нищо страшно.
        }
    }

    /**
     * Пази новия рекорден дух (тихо — квотата на localStorage не е гарантирана).
     *
     * @param {Float32Array} frames
     * @param {number} lapTicks
     */
    #saveGhost(frames, lapTicks) {
        // Духът в паметта се обновява ВИНАГИ — квотата на localStorage може
        // да провали само персистирането, не тазсесийния съперник.
        if (this.ghost?.official && !this.rivalGhost) {
            tintGhostRig(this.ghostRig, 0x9fc8ff); // вече е личният, син
        }
        this.ghost = { frames, lapTicks };

        try {
            localStorage.setItem(
                ghostKey(this.track.slug),
                JSON.stringify({ v: SIM_VERSION, lapTicks, frames: encodeFrames(frames) })
            );
        } catch {
            // Пълно/блокирано хранилище — духът просто не се запазва за после.
        }
    }

    #placeCameraBehindCar() {
        const state = this.sim.state;
        const forwardX = Math.sin(state.heading);
        const forwardZ = Math.cos(state.heading);

        this.camera.position.set(
            state.x - forwardX * CAMERA.distance,
            this.sim.surface.height + CAMERA.height,
            state.z - forwardZ * CAMERA.distance
        );
        this.camera.lookAt(state.x, this.sim.surface.height + 0.6, state.z);
    }

    #bindEvents() {
        this.onKeyDown = (event) => {
            if (INTERESTING_KEYS.has(event.code)) {
                event.preventDefault();
                this.keys.add(event.code);
            }

            // R преди старта би убил attract демото зад pre-start екрана
            // (reset → stopReplay нулира replay и цикълът му умира на място) —
            // рестартът има смисъл само след „Карай".
            if (event.code === 'KeyR' && this.started) {
                this.reset(true);
            }

            // C превключва chase ↔ бордова (halo) камера (не и в ТВ реплей).
            if (event.code === 'KeyC' && !event.repeat && !this.replay) {
                this.cameraMode = this.cameraMode === 'chase' ? 'onboard' : 'chase';
                this.halo.visible = this.cameraMode === 'onboard';
                this.lookTarget = null; // погледът да не замахне от старата точка
            }

            // M заглушава/пуска звука (не и в ТВ реплей — там звукът е спрян
            // и unmute би пуснал двигателя на замразени обороти за миг).
            if (event.code === 'KeyM' && !event.repeat && !this.replay) {
                this.sound.setMuted(!this.sound.muted());
            }

            // Ръчна трансмисия: W = нагоре, S = надолу (веднъж на натискане —
            // event.repeat спира повтарянето при задържане).
            if (this.manualTransmission && !event.repeat) {
                if (event.code === 'KeyW') {
                    shiftUp(this.drivetrain);
                } else if (event.code === 'KeyS') {
                    shiftDown(this.drivetrain);
                }
            }
        };

        this.onKeyUp = (event) => {
            this.keys.delete(event.code);
        };

        // Alt-Tab по време на завой оставя клавиша „натиснат" завинаги.
        // Звукът също спира: за разлика от visibilitychange, blur хваща и
        // фокус към ДРУГО приложение при все още видим браузър (Windows) —
        // иначе двигателят бучи, докато човекът си гледа пощата.
        this.onBlur = () => {
            this.keys.clear();
            this.sound.stop();
        };
        this.onFocus = () => {
            if (this.running && !this.replay && !document.hidden) {
                this.sound.start();
            }
        };

        // В скрит таб rAF спира, но Web Audio продължава — двигателят би
        // бучал на замразени обороти до безкрай. Спираме/пускаме със скриването.
        // !replay като в onFocus: ТВ реплеят е без двигател и връщането в таба
        // не бива да пуска замразения дрон върху него.
        this.onVisibility = () => {
            if (document.hidden) {
                this.sound.stop();
            } else if (this.running && !this.replay) {
                this.sound.start();
            }
        };

        window.addEventListener('keydown', this.onKeyDown);
        window.addEventListener('keyup', this.onKeyUp);
        window.addEventListener('blur', this.onBlur);
        window.addEventListener('focus', this.onFocus);
        document.addEventListener('visibilitychange', this.onVisibility);
    }

    #unbindEvents() {
        window.removeEventListener('keydown', this.onKeyDown);
        window.removeEventListener('keyup', this.onKeyUp);
        window.removeEventListener('blur', this.onBlur);
        window.removeEventListener('focus', this.onFocus);
        document.removeEventListener('visibilitychange', this.onVisibility);
    }

    #readInput() {
        // Директни проверки, без closure/rest-масиви — извиква се на всеки кадър.
        // При ръчна трансмисия W/S са за смяна на предавка → само стрелките карат.
        const keys = this.keys;
        const ws = !this.manualTransmission;
        const throttle = keys.has('ArrowUp') || (ws && keys.has('KeyW')) ? 1 : 0;
        const brake = keys.has('ArrowDown') || keys.has('Space') || (ws && keys.has('KeyS')) ? 1 : 0;
        const steer =
            (keys.has('ArrowLeft') || keys.has('KeyA') ? -1 : 0) +
            (keys.has('ArrowRight') || keys.has('KeyD') ? 1 : 0);

        this.input.brake = Math.max(brake, this.touch.brake);
        // Авто-газ: пълна газ, освен когато спираш (спирачката вдига газта). Иначе
        // нормалната газ от клавиатура/тъч.
        this.input.throttle = this.autoThrottle
            ? (this.input.brake > 0 ? 0 : 1)
            : Math.max(throttle, this.touch.throttle);

        // Дясноориентирана three.js сцена + chase камера зад колата → физическото
        // „надясно" (+x) се РЕНДЕРИРА вляво на екрана. Обръщаме тук (клавиатура и
        // тъч наведнъж), за да съвпада натиснатата посока с видяната. Физиката
        // (physics.js) остава недокосната — тя е чиста функция за replay.
        const merged = steer !== 0 ? steer : this.touch.steer;
        this.input.steer = -merged;
    }

    /**
     * Летящата обиколка завърши: духът се обновява при рекорд, кадрите остават
     * за ТВ реплея, а трейсът тръгва към UI-а (и оттам — към сървъра).
     *
     * @param {object} event Събитието от sim.tick
     */
    #onLapFinished(event) {
        // Състезание: няма резултатен екран по средата — следващата обиколка
        // се въоръжава ВЕДНАГА (не през 'formation', иначе се хронометрира
        // само всяка втора). Кадрите на ПОСЛЕДНАТА обиколка хранят ТВ реплея
        // на подиума; финалът идва от #finishRace след RACE_TOTAL_LAPS.
        if (this.opponents.length > 0) {
            if (event.frames) {
                this.lastLapFrames = event.frames;
            }
            this.sim.rearmFlyingLap();
            return;
        }

        if (event.frames) {
            this.lastLapFrames = event.frames;

            // Духът е еталонът за СОЛО атака — обиколка, „подпомогната" от
            // удари/драфт в състезание, не бива да го замърсява. Официалният
            // дух е само заместител: ПЪРВАТА ти валидна обиколка го измества,
            // дори да е по-бавна — иначе личният рекорд изобщо не се записва,
            // докато не биеш автопилота.
            if (
                event.valid &&
                this.opponents.length === 0 &&
                (this.ghost === null || this.ghost.official || event.lapTicks < this.ghost.lapTicks)
            ) {
                // Камбанка само за истинско подобрение (не за първата/официалния).
                if (this.ghost !== null && !this.ghost.official && event.lapTicks < this.ghost.lapTicks) {
                    this.sound.recordChime();
                }
                this.#saveGhost(event.frames, event.lapTicks);
            }
        }

        // Дотук стигат само соло обиколки (състезанието излезе по-горе) —
        // затова времето е възпроизводимо и може да върви към класацията.
        this.onFinish({
            lapMs: event.lapMs,
            sectorsMs: event.sectorsMs,
            valid: event.valid,
            // Записът на входа — доказателството на обиколката за сървъра.
            trace: event.trace ? encodeTrace(event.trace) : null,
            simVersion: SIM_VERSION,
        });
    }

    /**
     * @param {number} dt
     * @param {import('./physics.js').CarState} state Интерполирано състояние за рендер
     */
    #updateCamera(dt, state) {
        const forwardX = Math.sin(state.heading);
        const forwardZ = Math.cos(state.heading);

        // ── Бордова (halo) камера: твърдо закачена за болида ────────────────
        // Без изглаждане на позицията — истинската onboard се тресе с колата,
        // това Е усещането. Погледът напред остава леко изгладен.
        if (this.cameraMode === 'onboard') {
            this.camera.position.set(
                state.x + forwardX * CAMERA.onboardForward,
                this.sim.surface.height + CAMERA.onboardHeight,
                state.z + forwardZ * CAMERA.onboardForward
            );

            const { ys, spacing, count } = this.track;
            const hint = this.sim.trackIndexHint;
            const aheadIndex =
                hint === null
                    ? 0
                    : (hint + Math.round(CAMERA.onboardLookAhead / spacing)) % count;

            const lookX = state.x + forwardX * CAMERA.onboardLookAhead;
            const lookY = ys[aheadIndex] + 1.0;
            const lookZ = state.z + forwardZ * CAMERA.onboardLookAhead;

            const kLook = 1 - Math.exp(-14 * dt);
            if (!this.lookTarget) {
                this.lookTarget = new THREE.Vector3(lookX, lookY, lookZ);
            } else {
                this.lookTarget.x += (lookX - this.lookTarget.x) * kLook;
                this.lookTarget.y += (lookY - this.lookTarget.y) * kLook;
                this.lookTarget.z += (lookZ - this.lookTarget.z) * kLook;
            }
            this.camera.lookAt(this.lookTarget);

            // G-force: спирачката навежда носа, завоят накланя главата. Малки
            // ъгли (до ~3°), но продават претоварването по-добре от всичко.
            this.camera.rotateX(this.gLong * 0.0012);
            this.camera.rotateZ(-this.gLat * 0.0022);

            // Воланът следва реалния ъгъл (визуално ~100° до упор).
            this.steeringWheel.rotation.z = state.steer * 1.8;

            this.#updateFov(dt, state);
            return;
        }

        const targetX = state.x - forwardX * CAMERA.distance;
        const targetZ = state.z - forwardZ * CAMERA.distance;

        // Камерата виси над асфалта, не над абсолютната нула — иначе на Спа
        // потъва в хълма при изкачването и увисва в небето при спускането.
        const targetY = this.sim.surface.height + CAMERA.height;

        // Експоненциално изглаждане — не зависи от честотата на кадрите,
        // за разлика от наивния lerp с константен коефициент.
        const k = 1 - Math.exp(-CAMERA.followDamping * dt);

        this.camera.position.x += (targetX - this.camera.position.x) * k;
        this.camera.position.z += (targetZ - this.camera.position.z) * k;
        this.camera.position.y += (targetY - this.camera.position.y) * k;

        // Погледът се насочва към височината на трасето НАПРЕД, не към тази
        // под колата: на билото това открива какво идва, вместо да опира в небе.
        const { ys, spacing, count } = this.track;
        const hint = this.sim.trackIndexHint;
        const aheadIndex =
            hint === null
                ? 0
                : (hint + Math.round(CAMERA.lookAhead / spacing)) % count;

        const lookX = state.x + forwardX * CAMERA.lookAhead;
        const lookY = ys[aheadIndex] + 0.9;
        const lookZ = state.z + forwardZ * CAMERA.lookAhead;

        // Look-таргетът се изглажда: `ys[aheadIndex]` е дискретна на осева точка
        // и без това погледът подскача вертикално при всяко прекосяване — това е
        // тресенето, което остана след изглаждането на пича.
        if (!this.lookTarget) {
            this.lookTarget = new THREE.Vector3(lookX, lookY, lookZ);
        } else {
            this.lookTarget.x += (lookX - this.lookTarget.x) * k;
            this.lookTarget.y += (lookY - this.lookTarget.y) * k;
            this.lookTarget.z += (lookZ - this.lookTarget.z) * k;
        }

        this.camera.lookAt(this.lookTarget);

        this.#updateFov(dt, state);
    }

    /**
     * Разширяването на зрителното поле със скоростта е основният трик за
     * усещане за скорост — по-силен от самото движение. Общо за двете камери.
     *
     * @param {number} dt
     * @param {import('./physics.js').CarState} state
     */
    #updateFov(dt, state) {
        const speedRatio = clamp01(Math.abs(state.vForward) / CAR.maxSpeed);
        const targetFov = CAMERA.fovIdle + (CAMERA.fovFast - CAMERA.fovIdle) * speedRatio;

        // По-широк праг (0.01→0.05): щом fov се е установил, спираме да
        // преизчисляваме проекционната матрица всеки кадър при почти-константна скорост.
        if (Math.abs(this.camera.fov - targetFov) > 0.05) {
            const k = 1 - Math.exp(-CAMERA.followDamping * dt);
            this.camera.fov += (targetFov - this.camera.fov) * k;
            this.camera.updateProjectionMatrix();
        }
    }

    #frame = (now) => {
        if (!this.running) {
            return;
        }

        this.rafId = requestAnimationFrame(this.#frame);

        const rawDt = (now - this.lastFrame) / 1000;
        const dt = Math.min(rawDt, MAX_FRAME_TIME);
        this.lastFrame = now;

        // Динамична резолюция: реалният кадър (преди клампата) управлява
        // мащаба — хваща и слаби устройства, и thermal throttling.
        this.#governResolution(rawDt);

        // ── ТВ реплей: симулацията е замразена, кадрите се превъртат ────────
        if (this.replay) {
            this.#replayFrame(dt);
            return;
        }

        // ── Стартова процедура: решетката чака светлините да угаснат ───────
        if (this.launch) {
            this.#launchFrame(dt);
            return;
        }

        this.#readInput();

        this.accumulator += dt;

        const sim = this.sim;
        const state = sim.state;

        // Снапшот ПРЕДИ стъпките от този кадър. Ако не се завърти стъпка (висок
        // FPS), prev == state и рендерът стои неподвижен — без трептене.
        let prevX = state.x;
        let prevZ = state.z;
        let prevHeading = state.heading;

        while (this.accumulator >= FIXED_DT) {
            prevX = state.x;
            prevZ = state.z;
            prevHeading = state.heading;

            const event = sim.tick(this.input);
            if (event?.type === 'finished') {
                this.#onLapFinished(event);
            }

            // Съперниците тиктакат в същия фиксиран ритъм, всеки в своя
            // симулация.
            for (const opp of this.opponents) {
                const os = opp.sim.state;
                opp.prevX = os.x;
                opp.prevZ = os.z;
                opp.prevHeading = os.heading;

                driveAutopilot(opp.sim, opp.input, opp);
                const oppEvent = opp.sim.tick(opp.input);
                if (oppEvent?.type === 'finished') {
                    // Ботът не спира на резултатен екран — направо нова
                    // обиколка (и recovery мрежата остава активна).
                    opp.sim.phase = 'formation';
                }
            }

            // Контактите: всички коли се блъскат (и играчът). Затова времената
            // от състезание не отиват в класацията — сървърът не може да
            // преиграе чужди удари. Кола в „Връщане на пистата" е извадена.
            if (this.opponents.length > 0) {
                const cars = this._contactCars;
                cars.length = 0;
                if (!sim.recovering) {
                    cars.push(state);
                }
                for (const opp of this.opponents) {
                    if (!opp.sim.recovering) {
                        cars.push(opp.sim.state);
                    }
                }
                resolveCarContacts(cars, this._contacts);

                // Ударите С УЧАСТИЕ на играча хранят искри + звук (веднъж на
                // кадър — най-силният).
                for (const contact of this._contacts) {
                    if (contact.a !== state && contact.b !== state) {
                        continue;
                    }
                    if (contact.impulse < 1.2) {
                        continue;
                    }
                    if (this.pendingImpact === null || contact.impulse > this.pendingImpact.impulse) {
                        this.pendingImpact = contact;
                    }
                }
            }

            this.accumulator -= FIXED_DT;
        }

        // Изминат път за позицията П1..Пn — праговете хващат и пресичане на
        // линията, и връщане назад (телепорт от recovery през линията).
        trackWrap(this.playerRace, sim.lastProgress);
        for (const opp of this.opponents) {
            trackWrap(opp, opp.sim.lastProgress);
        }

        // Финал на състезанието: пресичане № RACE_TOTAL_LAPS+1 (първото е
        // потеглянето от решетката) = карирания флаг за играча.
        if (
            this.opponents.length > 0 &&
            !this.raceResult &&
            this.playerRace.laps > RACE_TOTAL_LAPS
        ) {
            this.#finishRace();
        }

        // След телепорт (връщане на пистата) не интерполираме от старото място —
        // иначе колата „прелита" през картата за един кадър. Камерата се залепя
        // наново, шейкът от старото място се нулира.
        if (sim.snapRender) {
            prevX = state.x;
            prevZ = state.z;
            prevHeading = state.heading;
            sim.snapRender = false;
            this.cameraShakeOffset = 0;
            this.lookTarget = null;
            this.#placeCameraBehindCar();
        }

        // Интерполация между последните две стъпки. Физиката тиктака на 120 Hz,
        // но кадрите идват на променлива честота — без това колата и камерата
        // подскачат/дърпат, особено щом FPS-ът се разклати. alpha е остатъкът от
        // акумулатора: 0 = точно на стъпка, →1 = почти на следващата.
        let dHeading = state.heading - prevHeading;
        // Пази срещу евентуален wrap на heading в [-π, π].
        if (dHeading > Math.PI) dHeading -= 2 * Math.PI;
        else if (dHeading < -Math.PI) dHeading += 2 * Math.PI;

        // Преизползван обект вместо spread на всеки кадър — нула алокации.
        const alpha = this.accumulator / FIXED_DT;
        const render = this._render;
        Object.assign(render, state);
        render.x = prevX + (state.x - prevX) * alpha;
        render.z = prevZ + (state.z - prevZ) * alpha;
        render.heading = prevHeading + dHeading * alpha;

        updateCarRig(this.carRig, render, sim.surface, dt);

        // Духът: полупрозрачният съперник повтаря рекордната обиколка, тик
        // по тик срещу твоя хронометър — вижда се само на летящата обиколка.
        this.#updateGhost();

        // Съперниците: интерполация като при играча.
        this.#updateOpponents(alpha, dt);

        // Изгладени ускорения за G-force наклоните на бордовата камера.
        const renderV = state.vForward;
        const rawLong = dt > 0 ? Math.max(-50, Math.min(50, (renderV - this.prevRenderV) / dt)) : 0;
        this.prevRenderV = renderV;
        const rawLat = Math.max(-40, Math.min(40, state.yawRate * renderV));
        const kg = 1 - Math.exp(-6 * dt);
        this.gLong += (rawLong - this.gLong) * kg;
        this.gLat += (rawLat - this.gLat) * kg;

        // Шейкът от миналия кадър се маха ПРЕДИ изглаждането — chase камерата
        // интегрира от текущата си позиция и иначе офсетът се наслагва с
        // коефициент, зависещ от кадровата честота (на 240 Hz ставаше ~5×).
        this.camera.position.y -= this.cameraShakeOffset;
        this.#updateCamera(dt, render);

        // Тактилни повърхности: чакълът тресе камерата, кербът вибрира болида.
        // Чисто визуални — физиката вече е сметната в стъпката.
        this.effectTime += dt;
        let shake = 0;
        if (sim.offSurface === 'gravel' && Math.abs(state.vForward) > 4) {
            shake = Math.sin(this.effectTime * 43) * 0.035 + Math.sin(this.effectTime * 61) * 0.02;
        }
        let rumble = 0;
        if (sim.onKerb && Math.abs(state.vForward) > 8) {
            rumble = Math.sin(this.effectTime * 85) * 0.014;
        }
        this.carRig.body.position.y = rumble;
        this.cameraShakeOffset =
            this.cameraMode === 'onboard' ? shake + rumble * 0.8 : shake * 0.45 + rumble * 0.2;
        this.camera.position.y += this.cameraShakeOffset;

        // Дим/чакъл/искри — данните (slip, повърхност) вече са сметнати.
        this.particles.update(dt, render, sim.surface.height, state, sim);

        // Следите от гуми: плъзгане или яко спиране с накъсана задница.
        const skidding =
            state.slip > 0.3 ||
            (this.input.brake > 0 && Math.abs(state.vForward) > 30 && state.slip > 0.12);
        this.skidMarks.update(skidding, render, sim.surface.height, sim.surface.bank);

        // Удар от този кадър: искри + звук + вибрация (Android).
        if (this.pendingImpact !== null) {
            const impact = this.pendingImpact;
            this.pendingImpact = null;
            const strength = Math.min(1, impact.impulse / 6);
            this.particles.burst(impact.x, sim.surface.height + 0.4, impact.z, strength);
            this.sound.impact(strength);
            navigator.vibrate?.(30);
        }

        // Кратък тактилен тик при качване на керб (Android; iOS няма API).
        if (sim.onKerb && !this.wasOnKerb) {
            navigator.vibrate?.(8);
        }
        this.wasOnKerb = sim.onKerb;

        // Маршалът вее карирания флаг само на летящата (финалната) обиколка.
        if (this.marshalFlag) {
            if (sim.phase === 'flying') {
                this.flagWave += dt * FLAG_WAVE_SPEED;
                this.marshalFlag.rotation.z = Math.sin(this.flagWave) * FLAG_WAVE_AMP;
            } else if (this.marshalFlag.rotation.z !== 0) {
                this.marshalFlag.rotation.z = 0; // в покой прътът е изправен
            }
        }

        // Декор анимации (виенското колело на Сузука се върти бавно).
        for (const animate of this.decorAnimations) {
            animate(dt);
        }

        // Жълт флаг: най-близкият маршалски пост вее, докато тече връщането.
        if (sim.recovering) {
            if (!this.activeYellowPost && this.marshalPosts.length > 0) {
                const target = sim.safeState.index;
                const count = this.track.count;
                let best = null;
                let bestDistance = Infinity;
                for (const post of this.marshalPosts) {
                    const forward = (((post.index - target) % count) + count) % count;
                    const distance = Math.min(forward, count - forward);
                    if (distance < bestDistance) {
                        bestDistance = distance;
                        best = post;
                    }
                }
                this.activeYellowPost = best;
            }
            if (this.activeYellowPost) {
                this.activeYellowPost.pivot.rotation.z = 0.15 + Math.sin(this.effectTime * 7) * 0.45;
            }
        } else if (this.activeYellowPost) {
            this.activeYellowPost.pivot.rotation.z = 1.25; // прибран
            this.activeYellowPost = null;
        }

        // Петте светлини на гантрито: в соло — червени през загряващата,
        // гаснат на летящата. В състезание ги командва #launchFrame, а след
        // потеглянето стоят угаснали („lights out and away we go").
        if (this.startLights) {
            const target = sim.phase === 'formation' && this.opponents.length === 0 ? 3.2 : 0;
            for (const material of this.startLights) {
                if (material.emissiveIntensity !== target) {
                    material.emissiveIntensity = target;
                }
            }
        }

        // Трансмисия (обороти/предавка за HUD) — гладко, всеки кадър.
        const gearBefore = this.drivetrain.gear;
        updateDrivetrain(this.drivetrain, state.vForward, this.input.throttle);

        // Смяната на предавка: звуковият „крак"/blip + пламък от ауспуха.
        if (this.drivetrain.gear !== gearBefore && Math.abs(state.vForward) > 2) {
            this.sound.shift(this.drivetrain.gear > gearBefore ? 1 : -1);
            this.exhaustFlash = 0.09;
        }

        // Спирачно греене + пламък от ауспуха.
        this.#updateCarLights(dt, state);

        // Звукът следва реалните обороти + повърхността под колата.
        // Тълпата се чува при трибуните на старт-финала; тунелът (Монако)
        // включва риверба.
        const progressMeters = sim.lastProgress * this.track.length;
        const startDistance = Math.min(progressMeters, this.track.length - progressMeters);
        const tunnel = this.circuit.tunnel;

        this.sound.update(this.drivetrain.rpm, this.input.throttle, {
            kerb: sim.onKerb,
            gravel: sim.offSurface === 'gravel',
            speed: Math.abs(state.vForward),
            slip: state.slip,
            brake: this.input.brake,
            crowd: this.circuit.startGrandstands
                ? Math.max(0, 1 - startDistance / 220)
                : 0,
            tunnel: tunnel !== undefined && progressMeters >= tunnel.from && progressMeters <= tunnel.to,
        });
        this.#updateRivalSound(render);

        // Сенчестата камера следва колата: посоката на слънцето е фиксирана,
        // движим само центъра, за да е острата сянка около играча.
        this.sun.target.position.set(render.x, sim.surface.height, render.z);
        this.sun.position.set(
            render.x + this.sunDir.x * 300,
            sim.surface.height + this.sunDir.y * 300,
            render.z + this.sunDir.z * 300
        );

        this.#render();

        // HUD телеметрия — не по-често от 30 Hz (виж TELEMETRY_INTERVAL): Vue
        // реактивността на всеки кадър е излишен diff/patch + GC натиск, а
        // рендерът вече е нарисуван. Таймерът остава гладък.
        this.telemetryAccum += dt;
        if (this.telemetryAccum < TELEMETRY_INTERVAL) {
            return;
        }
        this.telemetryAccum = 0;

        // Позиция в „състезанието": по МЯСТО на пистата (обиколки + прогрес).
        // Гридът стартира пред теб → тръгваш последен и гониш; изпревариш ли
        // кола физически, позицията пада веднага.
        let position = 1;
        let tower = null;
        if (this.opponents.length > 0) {
            const race = this.playerRace;
            const covered = race.laps + race.lastProgress;
            for (const opp of this.opponents) {
                if (opp.laps + opp.lastProgress > covered) {
                    position++;
                }
            }

            // Кулата с позициите: интервал до колата ОТПРЕД, в метри.
            const entries = [
                { name: null, isPlayer: true, covered },
                ...this.opponents.map((opp, i) => ({
                    name: BOT_NAMES[i % BOT_NAMES.length],
                    isPlayer: false,
                    covered: opp.laps + opp.lastProgress,
                })),
            ];
            entries.sort((a, b) => b.covered - a.covered);
            tower = entries.map((entry, idx) => ({
                name: entry.name,
                isPlayer: entry.isPlayer,
                gap: idx === 0
                    ? 0
                    : Math.round((entries[idx - 1].covered - entry.covered) * this.track.length),
            }));
        }

        // Живата делта срещу духа (соло/дуел) — зелено/червено в HUD-а.
        const ghostDelta = this.#ghostDelta();

        // Мини-картата: нормализирани точки на колите (30 Hz е достатъчно).
        const mapDots = [{ ...this.minimap.project(state.x, state.z), t: 0 }];
        for (const opp of this.opponents) {
            mapDots.push({ ...this.minimap.project(opp.sim.state.x, opp.sim.state.z), t: 1 });
        }
        if (this.ghostRig.root.visible) {
            const g = this.ghostRig.root.position;
            // Типът оцветява точката като 3D духа: официален златист (2),
            // личен син (3), дуелен фуксия (4) — виж DOT_COLORS в Index.vue.
            const t = this.rivalGhost ? 4 : this.ghost?.official ? 2 : 3;
            mapDots.push({ ...this.minimap.project(g.x, g.z), t });
        }

        this.onTelemetry({
            speed: Math.round(speedKmh(state)),
            rpm: Math.round(this.drivetrain.rpm),
            gear: this.drivetrain.gear,
            position,
            fieldSize: this.opponents.length + 1,
            raceLap: this.playerRace.laps,
            raceTotalLaps: this.opponents.length > 0 ? RACE_TOTAL_LAPS : 0,
            tower,
            ghostDelta,
            mapDots,
            lapTime: sim.phase === 'flying' ? sim.lapTicks * FIXED_DT : null,
            lastLap: sim.lastLapTicks === null ? null : sim.lastLapTicks * FIXED_DT,
            bestLap: sim.bestLapTicks === null ? null : sim.bestLapTicks * FIXED_DT,
            sector: sim.currentSector + 1,
            sectors: sim.lastSectors.map((t) => (t === null ? null : t * FIXED_DT)),
            lapValid: sim.lapValid,
            started: sim.phase === 'flying',
            phase: sim.phase,
            recovering: sim.recovering,
            recoverCount: sim.recovering ? Math.ceil(sim.recoverTicks / 120) : 0,
            gated: sim.phase === 'flying' && sim.timerGated,
            warnings: sim.warnings,
            maxWarnings: MAX_WARNINGS,
        });
    };

    /**
     * Карираният флаг: класирането се снима в момента на финала на играча
     * (по място на пистата — изпреварилите ботове са легитимно напред).
     */
    #finishRace() {
        const entries = [
            {
                name: null,
                isPlayer: true,
                covered: this.playerRace.laps + this.playerRace.lastProgress,
            },
            ...this.opponents.map((opp, i) => ({
                name: BOT_NAMES[i % BOT_NAMES.length],
                isPlayer: false,
                covered: opp.laps + opp.lastProgress,
            })),
        ];

        entries.sort((a, b) => b.covered - a.covered);

        const standings = entries.map((entry, i) => ({
            position: i + 1,
            name: entry.name,
            isPlayer: entry.isPlayer,
        }));

        this.raceResult = {
            position: standings.find((s) => s.isPlayer).position,
            standings,
        };
        this.sound.fanfare(this.raceResult.position);
        this.onRaceFinish(this.raceResult);
    }

    /**
     * Живата делта срещу духа: времето, на което духът е бил на ТОВА място,
     * срещу текущия хронометър. Търсенето в кадрите е локално около указател.
     *
     * @returns {number|null} Секунди (+ = изоставаш), null когато няма дуел
     */
    #ghostDelta() {
        const ghost = this.rivalGhost ?? this.ghost;
        const sim = this.sim;

        if (
            !ghost?.frames ||
            sim.phase !== 'flying' ||
            sim.timerGated ||
            this.opponents.length > 0
        ) {
            return null;
        }

        const frames = ghost.frames;
        const count = Math.floor(frames.length / 3);
        if (count < 2) {
            return null;
        }

        // Нова обиколка → указателят се връща на старта.
        if (sim.lapTicks < 10) {
            this.ghostDeltaHint = 0;
        }

        const x = sim.state.x;
        const z = sim.state.z;
        let best = this.ghostDeltaHint;
        let bestD = Infinity;
        const window = 150;

        // Кадрите са ЕДНА обиколка, не цикъл — клампваме търсенето вместо
        // модулно увиване: на линията увиването залепва за отсрещния край и
        // делтата проблясва ±цяла обиколка за един телеметричен кадър.
        const from = Math.max(0, this.ghostDeltaHint - 20);
        const to = Math.min(count - 1, this.ghostDeltaHint + window);
        for (let k = from; k <= to; k++) {
            const dx = x - frames[k * 3];
            const dz = z - frames[k * 3 + 2];
            const d = dx * dx + dz * dz;
            if (d < bestD) {
                bestD = d;
                best = k;
            }
        }

        this.ghostDeltaHint = best;

        // Далеч от линията на духа (излизане/recovery) — делтата лъже.
        if (bestD > 35 * 35) {
            return null;
        }

        // Същият -1 кадър като #updateGhost: frames[k] е състоянието СЛЕД
        // тик (k+1)·FRAME_EVERY — без корекцията делтата постоянно надписва
        // ~17 ms изоставане и спори с рендерирания дух.
        return (sim.lapTicks - (best + 1) * FRAME_EVERY) * FIXED_DT;
    }

    /**
     * Спирачно греене на задните колела + пламък от ауспуха. Закача се СЛЕД
     * attachCarModel (той скрива децата на body-то при подмяна с GLB) —
     * добавеното после остава видимо и се накланя с болида.
     */
    #buildCarLights() {
        if (this.disposed) {
            return;
        }

        const glowMaterial = new THREE.MeshBasicMaterial({
            color: 0xff5a20,
            transparent: true,
            opacity: 0,
            blending: THREE.AdditiveBlending,
            depthWrite: false,
        });

        this.brakeGlows = [];
        for (const side of [-1, 1]) {
            const disc = new THREE.Mesh(new THREE.CircleGeometry(0.17, 12), glowMaterial.clone());
            disc.position.set(side * 0.84, 0.37, -1.55);
            disc.rotation.y = side * (Math.PI / 2);
            // Тагът пази ефекта от attachCarModel: късен GLB скрива децата
            // на body-то, но тагнатите светлини остават видими.
            disc.userData.carLight = true;
            this.carRig.body.add(disc);
            this.brakeGlows.push(disc);
        }

        const flame = new THREE.Mesh(
            new THREE.ConeGeometry(0.09, 0.5, 6),
            new THREE.MeshBasicMaterial({
                color: 0xffa040,
                transparent: true,
                opacity: 0,
                blending: THREE.AdditiveBlending,
                depthWrite: false,
            })
        );
        // Върхът назад: конусът гледа по -z (върхът на ConeGeometry е по +Y,
        // Rx(-π/2) го обръща към -Z — колата гледа по +Z).
        flame.rotation.x = -Math.PI / 2;
        flame.position.set(0, 0.5, -2.45);
        flame.userData.carLight = true;
        this.carRig.body.add(flame);
        this.exhaust = flame;

        this.brakeGlowLevel = 0;
        this.exhaustFlash = 0;
    }

    /**
     * @param {number} dt
     * @param {import('./physics.js').CarState} state
     */
    #updateCarLights(dt, state) {
        if (!this.brakeGlows) {
            return;
        }

        // Дисковете се нагряват/охлаждат плавно — не са лампа on/off.
        const heating = this.input.brake > 0 && Math.abs(state.vForward) > 8 ? 1 : 0;
        this.brakeGlowLevel += (heating - this.brakeGlowLevel) * (1 - Math.exp(-6 * dt));
        const glow = this.brakeGlowLevel * 0.85;
        for (const disc of this.brakeGlows) {
            disc.material.opacity = glow;
        }

        // Пламъкът: проблясъкът идва от детекцията на смяната в #frame.
        this.exhaustFlash = Math.max(0, this.exhaustFlash - dt);

        const idleFlame = this.input.throttle > 0 && Math.abs(state.vForward) > 3 ? 0.1 : 0;
        this.exhaust.material.opacity = this.exhaustFlash > 0 ? 0.85 : idleFlame;
        const pulse = this.exhaustFlash > 0 ? 1.5 : 0.7;
        this.exhaust.scale.set(pulse, pulse, pulse);
    }

    /** Маха съперниците от сцената и освобождава ресурсите им. */
    #clearOpponents() {
        // Геометриите са споделени между риговете (виж setOpponents) —
        // всяка се освобождава по веднъж.
        const disposed = new Set();

        for (const opp of this.opponents) {
            this.scene.remove(opp.rig.root);
            opp.rig.root.traverse((object) => {
                if (object.isMesh && !disposed.has(object.geometry)) {
                    disposed.add(object.geometry);
                    object.geometry.dispose();
                }
            });
            for (const material of opp.rig.materials) {
                material.dispose();
            }
        }
        this.opponents = [];
    }

    /**
     * Нарежда решетката: съперниците стоят НЕПОДВИЖНИ зад стартовата линия,
     * шахматно като истински грид — бот 0 най-отпред, играчът последен (виж
     * #gridPlayer). Всички потеглят заедно при гаснене на светлините.
     */
    #gridOpponents() {
        const t = this.track;
        const n = this.opponents.length;

        for (let i = 0; i < n; i++) {
            const opp = this.opponents[i];
            const slot = this.#gridSlot(i);

            opp.sim.reset(false);
            const s = opp.sim.state;
            s.x = slot.x;
            s.z = slot.z;
            s.heading = slot.heading;
            s.vForward = 0; // стоящ старт — чака светлините
            opp.sim.trackIndexHint = slot.index;
            opp.sim.lastProgress = slot.progress;
            opp.sim.surface.height = slot.height;
            opp.sim.surface.gradient = t.gradient[slot.index];
            opp.sim.surface.bank = t.bankSlope[slot.index];

            opp.laps = 0;
            opp.lastProgress = slot.progress;
            opp.prevX = s.x;
            opp.prevZ = s.z;
            opp.prevHeading = s.heading;

            opp.rig.root.visible = true;
            updateCarRig(opp.rig, s, opp.sim.surface, 1);
        }
    }

    /**
     * Слот i на решетката (0 = най-отпред, до линията), шахматно ляво/дясно.
     *
     * @param {number} i
     * @returns {{index: number, x: number, z: number, heading: number,
     *           progress: number, height: number}}
     */
    #gridSlot(i) {
        const t = this.track;
        const backMeters = GRID_FIRST_ROW + i * GRID_ROW_GAP;
        const back = Math.round(backMeters / t.spacing) % t.count;
        const index = (t.count - back) % t.count;
        const lateral = (i % 2 === 0 ? 1 : -1) * GRID_LATERAL;

        return {
            index,
            x: t.xs[index] + t.nx[index] * lateral,
            z: t.zs[index] + t.nz[index] * lateral,
            heading: Math.atan2(t.tx[index], t.tz[index]),
            progress: index / t.count,
            height: t.ys[index] - lateral * t.bankSlope[index],
        };
    }

    /**
     * Играчът на последния ред на решетката (стоящ, зад линията). Първото
     * пресичане е потеглянето (gridCrossingsToSkip) — обиколка 1 е бойна,
     * хронометърът тръгва при следващото минаване на линията, на скорост,
     * за да са времената сравними с класацията.
     */
    #gridPlayer() {
        if (this.opponents.length === 0) {
            return;
        }

        const t = this.track;
        const sim = this.sim;
        const slot = this.#gridSlot(this.opponents.length);
        const s = sim.state;

        s.x = slot.x;
        s.z = slot.z;
        s.heading = slot.heading;
        s.vForward = 0;
        sim.trackIndexHint = slot.index;
        sim.lastProgress = slot.progress;
        sim.surface.height = slot.height;
        sim.surface.gradient = t.gradient[slot.index];
        sim.surface.bank = t.bankSlope[slot.index];
        sim.gridCrossingsToSkip = 1;
        sim.snapRender = true;
        this.lookTarget = null;
        this.#placeCameraBehindCar();
    }

    /** Въоръжава стартовата процедура (само в състезание). */
    #armLaunch() {
        this.launch =
            this.opponents.length > 0
                ? { elapsed: 0, hold: 0.6 + Math.random() * 0.9, prevLit: 0 }
                : null;
        this.onLaunch(this.launch ? 0 : null);
    }

    /**
     * Кадър от стартовата процедура: света е замръзнал, петте светлини се
     * палят една по една, произволна пауза — и гаснат: старт. Двигателят
     * реве все по-високо с всяка светлина.
     *
     * @param {number} dt
     */
    #launchFrame(dt) {
        const launch = this.launch;
        launch.elapsed += dt;

        const lit = Math.min(5, Math.floor(launch.elapsed / LAUNCH_LIGHT_INTERVAL) + 1);
        const outAt = 4 * LAUNCH_LIGHT_INTERVAL + launch.hold;

        // Бийп на всяка нова светлина; по-висок и дълъг при гасенето.
        if (lit !== launch.prevLit) {
            launch.prevLit = lit;
            this.sound.beep(600 + lit * 40);
        }

        if (launch.elapsed >= outAt) {
            // Гаснат — и потегляме.
            if (this.startLights) {
                for (const material of this.startLights) {
                    material.emissiveIntensity = 0;
                }
            }
            this.sound.beep(980, 0.28);
            navigator.vibrate?.(40);
            this.launch = null;
            this.accumulator = 0;
            this.onLaunch(null);
            return;
        }

        if (this.startLights) {
            for (let i = 0; i < this.startLights.length; i++) {
                const target = i < lit ? 3.2 : 0;
                if (this.startLights[i].emissiveIntensity !== target) {
                    this.startLights[i].emissiveIntensity = target;
                }
            }
        }

        // Ревът на решетката се вдига с всяка светлина.
        this.sound.update(4500 + lit * 1900, lit >= 5 ? 0.5 : 0.25, {
            kerb: false,
            gravel: false,
            speed: 0,
        });

        for (const animate of this.decorAnimations) {
            animate(dt);
        }

        this.onLaunch(lit);
        this.#render();
    }

    /**
     * Рендер на съперниците: същата интерполация между стъпките като при играча.
     *
     * @param {number} alpha Остатък от акумулатора, [0..1)
     * @param {number} dt
     */
    #updateOpponents(alpha, dt) {
        for (const opp of this.opponents) {
            const s = opp.sim.state;

            // Телепорт (recovery) — без интерполация през картата.
            if (opp.sim.snapRender) {
                opp.prevX = s.x;
                opp.prevZ = s.z;
                opp.prevHeading = s.heading;
                opp.sim.snapRender = false;
            }

            let dH = s.heading - opp.prevHeading;
            if (dH > Math.PI) dH -= 2 * Math.PI;
            else if (dH < -Math.PI) dH += 2 * Math.PI;

            const render = opp._render;
            Object.assign(render, s);
            render.x = opp.prevX + (s.x - opp.prevX) * alpha;
            render.z = opp.prevZ + (s.z - opp.prevZ) * alpha;
            render.heading = opp.prevHeading + dH * alpha;

            updateCarRig(opp.rig, render, opp.sim.surface, dt);
        }
    }

    /**
     * Най-близкият съперник в ухото: сила по разстоянието, панорама по
     * страната спрямо камерата.
     *
     * @param {object} render Интерполираното състояние на играча
     */
    #updateRivalSound(render) {
        if (this.opponents.length === 0) {
            return;
        }

        let nearest = Infinity;
        let speed = 0;
        let nx = 0;
        let nz = 0;

        for (const opp of this.opponents) {
            const s = opp.sim.state;
            const d = Math.hypot(s.x - render.x, s.z - render.z);
            if (d < nearest) {
                nearest = d;
                speed = Math.abs(s.vForward);
                nx = s.x;
                nz = s.z;
            }
        }

        // Панорама: проекция върху дясната ос на камерата (матрицата е от
        // предния кадър — закъснение от 1 кадър, нечуто).
        let pan = 0;
        if (Number.isFinite(nearest) && nearest > 0.001) {
            const e = this.camera.matrixWorld.elements;
            pan = ((nx - this.camera.position.x) * e[0] + (nz - this.camera.position.z) * e[2]) / 25;
        }

        // Доплер: радиална скорост от разликата на дистанциите между кадри.
        // Голям скок = смяна на най-близкия/телепорт — нулира се, не „свисти".
        let closing = 0;
        if (
            Number.isFinite(nearest) &&
            this.prevRivalDistance !== null &&
            Math.abs(this.prevRivalDistance - nearest) < 15
        ) {
            const frameDt = Math.max(1 / 240, (performance.now() - this.prevRivalTime) / 1000);
            closing = (this.prevRivalDistance - nearest) / frameDt;
        }
        this.prevRivalDistance = Number.isFinite(nearest) ? nearest : null;
        this.prevRivalTime = performance.now();

        this.sound.updateRival(nearest, speed, pan, closing);
    }

    /**
     * Духът: интерполира кадрите на рекордната обиколка спрямо ТЕКУЩИЯ
     * хронометър — истинска задочна битка, паузите (гейт) спират и двамата.
     */
    #updateGhost() {
        const sim = this.sim;
        // Дуелният дух (класацията) има предимство пред личния/официалния.
        const ghost = this.rivalGhost ?? this.ghost;

        // Духът е СОЛО фийчър: в състезание позлатеният официален дух би
        // карал „през" полето като седми, недосегаем съперник — объркващо,
        // при това в цвят близък до жълтата ливрея.
        if (!ghost || sim.phase !== 'flying' || this.opponents.length > 0) {
            this.ghostRig.root.visible = false;
            return;
        }

        const frames = ghost.frames;
        const frameCount = Math.floor(frames.length / 3);
        // -1 кадър: frames[k] е състоянието СЛЕД отброен тик 2(k+1) — без
        // корекцията духът върви ~17 ms пред реалната си позиция и „бие"
        // играч, който точно изравнява рекорда.
        const position = Math.max(0, sim.lapTicks / FRAME_EVERY - 1);
        const base = Math.floor(position);

        if (base >= frameCount - 1) {
            // Духът вече е финиширал — прибира се.
            this.ghostRig.root.visible = false;
            return;
        }

        const t = position - base;
        const i0 = base * 3;
        const i1 = i0 + 3;

        const x = frames[i0] + (frames[i1] - frames[i0]) * t;
        const z = frames[i0 + 2] + (frames[i1 + 2] - frames[i0 + 2]) * t;
        let dH = frames[i1 + 1] - frames[i0 + 1];
        if (dH > Math.PI) dH -= 2 * Math.PI;
        else if (dH < -Math.PI) dH += 2 * Math.PI;
        const heading = frames[i0 + 1] + dH * t;

        const root = this.ghostRig.root;
        root.visible = true;
        root.position.set(x, this.#ghostHeight(x, z), z);
        root.rotation.y = heading;
    }

    /**
     * Височината на асфалта под духа (собствена проекция, без да пипа
     * кеша на играча).
     *
     * @param {number} x
     * @param {number} z
     * @returns {number}
     */
    #ghostHeight(x, z) {
        const t = this.track;
        let best = 0;
        let bestDistSq = Infinity;

        // Груб скан на всяка 4-та точка — духът е визуален, сантиметри не личат.
        for (let i = 0; i < t.count; i += 4) {
            const dx = x - t.xs[i];
            const dz = z - t.zs[i];
            const d = dx * dx + dz * dz;
            if (d < bestDistSq) {
                bestDistSq = d;
                best = i;
            }
        }

        const lat = (x - t.xs[best]) * t.nx[best] + (z - t.zs[best]) * t.nz[best];

        return t.ys[best] - lat * t.bankSlope[best];
    }

    /**
     * Кадър от ТВ реплея: колата повтаря записа, камерата снима от крайпътни
     * постове с телевизионна режисура (задръж докато отмине, режи напред).
     *
     * @param {number} dt
     */
    #replayFrame(dt) {
        const replay = this.replay;
        const frames = replay.frames;
        const frameCount = Math.floor(frames.length / 3);

        // Живите частици догарят, спирачното греене гасне — без нови спаунове.
        this.particles.tick(dt);
        if (this.brakeGlows) {
            for (const disc of this.brakeGlows) {
                disc.material.opacity *= 0.9;
            }
            this.exhaust.material.opacity *= 0.9;
        }

        // Декорът живее и в реплея: attract демото върти този кадър с минути
        // зад pre-start екрана — замръзнал хеликоптер/знамена издават сцената.
        for (const animate of this.decorAnimations) {
            animate(dt);
        }

        // 60 кадъра/секунда реално време.
        replay.t += dt * (120 / FRAME_EVERY);
        if (replay.t >= frameCount - 1) {
            replay.t = 0; // цикли — играчът спира с бутона
        }

        const base = Math.floor(replay.t);
        const t = replay.t - base;
        const i0 = base * 3;
        const i1 = i0 + 3;

        const x = frames[i0] + (frames[i1] - frames[i0]) * t;
        const z = frames[i0 + 2] + (frames[i1 + 2] - frames[i0 + 2]) * t;
        let dH = frames[i1 + 1] - frames[i0 + 1];
        if (dH > Math.PI) dH -= 2 * Math.PI;
        else if (dH < -Math.PI) dH += 2 * Math.PI;
        const heading = frames[i0 + 1] + dH * t;

        const y = this.#ghostHeight(x, z);
        const render = this._render;
        Object.assign(render, this.sim.state);
        render.x = x;
        render.z = z;
        render.heading = heading;
        render.vForward = 40; // колелата да се въртят правдоподобно
        render.vLateral = 0;
        render.yawRate = 0;

        this.carRig.root.position.set(x, y, z);
        this.carRig.root.rotation.y = heading;
        // Кренът/пичът от последния жив кадър гаснат — ТВ колата се търкаля
        // равно, вместо да носи замразен наклон от финалната права.
        this.carRig.root.rotation.x *= 0.92;
        this.carRig.body.rotation.z *= 0.92;
        this.carRig.body.rotation.x *= 0.92;
        this.carRig.body.position.y = 0;
        const spin = 40 * dt * 2.2;
        for (const wheel of this.carRig.allWheels) {
            wheel.rotation.x += spin;
        }

        // Сянката следва реплей колата, не замразената жива позиция.
        this.sun.target.position.set(x, y, z);
        this.sun.position.set(
            x + this.sunDir.x * 300,
            y + this.sunDir.y * 300,
            z + this.sunDir.z * 300
        );

        // ТВ пост: държим текущия, докато колата не се отдалечи твърде много —
        // тогава режем към най-близкия напред (хистерезисът маха трептенето).
        const posts = this.#tvPosts();
        let current = replay.camIndex >= 0 ? posts[replay.camIndex] : null;
        const distTo = (post) => Math.hypot(x - post.x, z - post.z);

        if (!current || distTo(current) > 170) {
            let bestIdx = 0;
            let bestDist = Infinity;
            for (let i = 0; i < posts.length; i++) {
                const d = distTo(posts[i]);
                if (d < bestDist) {
                    bestDist = d;
                    bestIdx = i;
                }
            }
            replay.camIndex = bestIdx;
            current = posts[bestIdx];
        }

        this.camera.position.set(current.x, current.y, current.z);
        this.camera.lookAt(x, y + 0.8, z);

        if (Math.abs(this.camera.fov - 48) > 0.5) {
            this.camera.fov = 48; // телеобектив — истинската ТВ картина
            this.camera.updateProjectionMatrix();
        }

        this.#render();
    }

    /**
     * Крайпътните ТВ постове: на всеки ~180 m, отместени встрани и нагоре.
     * Строят се веднъж при първия реплей.
     */
    #tvPosts() {
        if (this._tvPosts) {
            return this._tvPosts;
        }

        const t = this.track;
        const every = Math.max(1, Math.round(180 / t.spacing));
        const posts = [];

        for (let i = 0; i < t.count; i += every) {
            // Редуваме страната — ТВ режисурата не стои все отляво.
            const side = posts.length % 2 === 0 ? 1 : -1;
            const offset = side * (t.halfWidths[i] + 16);
            posts.push({
                x: t.xs[i] + t.nx[i] * offset,
                y: t.ys[i] + 7 - offset * t.bankSlope[i],
                z: t.zs[i] + t.nz[i] * offset,
            });
        }

        this._tvPosts = posts;

        return posts;
    }
}

const INTERESTING_KEYS = new Set([
    'ArrowUp',
    'ArrowDown',
    'ArrowLeft',
    'ArrowRight',
    'KeyW',
    'KeyA',
    'KeyS',
    'KeyD',
    'Space',
]);

/**
 * Духът: процедурният болид, полупрозрачен и без сянка — рекордната обиколка,
 * каращa редом. Външният GLB не се клонира нарочно: духът се чете по-ясно
 * като силует.
 *
 * @returns {ReturnType<typeof buildCar>}
 */
function buildGhostRig() {
    const rig = buildCar();
    rig.tintables = [];

    rig.root.traverse((object) => {
        if (object.isMesh) {
            const material = object.material.clone();
            material.transparent = true;
            material.opacity = 0.35;
            material.depthWrite = false;
            if (material.color) {
                // Базовият цвят се пази — тонът се сменя (личен син ↔
                // официален златист) без да се наслагват lerp-ове.
                rig.tintables.push({ material, base: material.color.clone() });
            }
            object.material = material;
            object.castShadow = false;
        }
    });

    tintGhostRig(rig, 0x9fc8ff); // призрачно-син = личният рекорд

    return rig;
}

/**
 * Тонира духа: личен (син) или официалният на Падок (златист).
 *
 * @param {ReturnType<typeof buildGhostRig>} rig
 * @param {number} tint
 */
function tintGhostRig(rig, tint) {
    const color = new THREE.Color(tint);
    for (const { material, base } of rig.tintables) {
        material.color.copy(base).lerp(color, 0.7);
    }
}

/**
 * Съперник: процедурният болид с генерична ливрея (само боята се сменя —
 * никакви реални отбори). Материалите са клонирани per-кола, за да може
 * изсветляването при близост да не пипа другите.
 *
 * @param {number} color
 * @returns {ReturnType<typeof buildCar> & {materials: THREE.Material[]}}
 */
function buildOpponentRig(color) {
    const rig = buildCar();
    const materials = [];
    const cloned = new Map();

    rig.root.traverse((object) => {
        if (!object.isMesh) {
            return;
        }

        let material = cloned.get(object.material);
        if (!material) {
            material = object.material.clone();
            // Боядисва се само боята (MeshPhysical) — гуми/тъмни части остават.
            if (material.isMeshPhysicalMaterial) {
                material.color.set(color);
            }
            cloned.set(object.material, material);
            materials.push(material);
        }
        object.material = material;
        object.castShadow = true;
    });

    rig.materials = materials;

    return rig;
}

/**
 * Брои пресичанията на стартовата линия (в двете посоки) по прогреса.
 *
 * @param {{laps: number, lastProgress: number}} entry
 * @param {number} progress
 */
function trackWrap(entry, progress) {
    if (entry.lastProgress > 0.85 && progress < 0.15) {
        entry.laps++;
    } else if (entry.lastProgress < 0.15 && progress > 0.85) {
        entry.laps--;
    }
    entry.lastProgress = progress;
}

/**
 * Детерминиран PRNG (mulberry32) — решетката на съперниците е една и съща
 * при всяко зареждане на пистата.
 *
 * @param {number} seed
 * @returns {() => number} [0, 1)
 */
function mulberry32(seed) {
    let a = seed >>> 0;

    return () => {
        a = (a + 0x6d2b79f5) >>> 0;
        let t = a;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

/**
 * FNV-1a хеш на низ → seed за mulberry32.
 *
 * @param {string} value
 * @returns {number}
 */
function hashString(value) {
    let hash = 2166136261;

    for (let i = 0; i < value.length; i++) {
        hash ^= value.charCodeAt(i);
        hash = Math.imul(hash, 16777619);
    }

    return hash >>> 0;
}

/**
 * Мини-картата: нормализира трасето в квадрат [0,1]² (центрирано) и дава
 * project() за живите точки. Строи се веднъж; Vue рисува по canvas.
 *
 * @param {import('./track.js').Track} track
 * @returns {{path: Array<[number, number]>, project: (x: number, z: number) => {x: number, y: number}}}
 */
function buildMinimap(track) {
    let minX = Infinity;
    let maxX = -Infinity;
    let minZ = Infinity;
    let maxZ = -Infinity;

    for (let i = 0; i < track.count; i++) {
        minX = Math.min(minX, track.xs[i]);
        maxX = Math.max(maxX, track.xs[i]);
        minZ = Math.min(minZ, track.zs[i]);
        maxZ = Math.max(maxZ, track.zs[i]);
    }

    const size = Math.max(maxX - minX, maxZ - minZ) || 1;
    const padX = (size - (maxX - minX)) / 2;
    const padZ = (size - (maxZ - minZ)) / 2;

    const project = (x, z) => ({
        x: (x - minX + padX) / size,
        y: (z - minZ + padZ) / size,
    });

    const path = [];
    const step = Math.max(1, Math.floor(track.count / 220));
    for (let i = 0; i < track.count; i += step) {
        const p = project(track.xs[i], track.zs[i]);
        path.push([p.x, p.y]);
    }

    return { path, project };
}

/**
 * Воланът за бордовата камера: обръч + спици + хъб, дете на halo групата.
 *
 * @returns {THREE.Group}
 */
function buildSteeringWheel() {
    const group = new THREE.Group();
    const dark = new THREE.MeshBasicMaterial({ color: 0x14161a });
    const accent = new THREE.MeshBasicMaterial({ color: 0x2c3038 });

    const rim = new THREE.Mesh(new THREE.TorusGeometry(0.16, 0.02, 6, 20), dark);
    group.add(rim);

    for (const angle of [0, (2 * Math.PI) / 3, (4 * Math.PI) / 3]) {
        const spoke = new THREE.Mesh(new THREE.BoxGeometry(0.028, 0.15, 0.015), accent);
        spoke.position.set(Math.sin(angle) * 0.075, Math.cos(angle) * 0.075, 0);
        spoke.rotation.z = -angle;
        group.add(spoke);
    }

    const hub = new THREE.Mesh(new THREE.CircleGeometry(0.045, 10), accent);
    hub.position.z = 0.008;
    group.add(hub);

    group.position.set(0, -0.32, -0.52);

    return group;
}

/**
 * Halo силуетът + ръбът на кокпита за бордовата камера. Дете на камерата —
 * MeshBasic черно, като сянка срещу светлината (както го вижда пилотът).
 *
 * @returns {THREE.Group}
 */
function buildHaloOverlay() {
    const group = new THREE.Group();
    const material = new THREE.MeshBasicMaterial({ color: 0x0c0d0f });

    // Обръчът на halo-то — горната дъга пред погледа.
    const hoop = new THREE.Mesh(new THREE.TorusGeometry(0.34, 0.024, 8, 28, Math.PI), material);
    hoop.position.set(0, 0.1, -0.62);
    group.add(hoop);

    // Централната стойка.
    const pylon = new THREE.Mesh(new THREE.BoxGeometry(0.035, 0.2, 0.05), material);
    pylon.position.set(0, 0.0, -0.6);
    group.add(pylon);

    // Ръбът на кокпита — долната дъга.
    const rim = new THREE.Mesh(new THREE.TorusGeometry(0.55, 0.08, 6, 24, Math.PI), material);
    rim.rotation.z = Math.PI;
    rim.position.set(0, -0.36, -0.78);
    group.add(rim);

    return group;
}

/**
 * @param {number} v
 * @returns {number}
 */
function clamp01(v) {
    return v < 0 ? 0 : v > 1 ? 1 : v;
}
