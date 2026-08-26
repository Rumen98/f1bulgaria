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

/** Под това разстояние до играча съперникът изсветлява — визуалният сигнал,
 *  че колите не се блъскат (колизии няма нарочно, виж setOpponents). */
const RIVAL_FADE_DISTANCE = 9;

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
 * да се смъкне post-processing-ът там, където fill-rate-ът е тесен.
 *
 * @returns {boolean}
 */
function isLowPowerDevice() {
    const ua = navigator.userAgent || '';
    const mobile = /Android|iPhone|iPad|iPod|Mobile/i.test(ua);
    const fewCores = (navigator.hardwareConcurrency || 8) <= 4;

    return mobile || fewCores;
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
        // Визуалната идентичност на пистата: питлейн, терен, светлина, а вече
        // и ГЕОМЕТРИЯ — widthProfile/banking влизат в prepareTrack (circuits.js).
        this.circuit = circuitFor(trackData.slug);
        this.track = prepareTrack(trackData, this.circuit);

        this.renderer = new THREE.WebGLRenderer({
            canvas,
            antialias: true,
            powerPreference: 'high-performance',
        });
        // Над 2 нищо не се печели визуално, а на телефон убива кадрите.
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));

        // Филмов tone mapping + сенки (Фаза 1 реализъм). Експозицията е част от
        // атмосферата на пистата (мек Спа срещу ярко крайбрежие в Зандвоорт).
        this.renderer.toneMapping = THREE.ACESFilmicToneMapping;
        this.renderer.toneMappingExposure = this.circuit.atmosphere.exposure;
        this.renderer.shadowMap.enabled = true;
        this.renderer.shadowMap.type = THREE.PCFSoftShadowMap;

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
        // остава процедурният силует по-горе.
        const carReady = attachCarModel(this.carRig, () => this.disposed || this.started);

        // HDRI-то и външният болид се зареждат асинхронно. Изчакваме ги ПРЕДИ
        // старта (виж Game/Index.vue), за да не подменят вида по средата на
        // играта. Никога не reject-ва — при липса остава процедурното.
        this.ready = Promise.all([envReady, carReady, trackReady]).then(() => this.#warmup());

        // Сенки: всичко ПРИЕМА сянка; хвърлят я колата и подбраният декор
        // близо до трасето (гантри, пит стена/гараж, гуми, табели, мостове —
        // виж castShadow в decor.js). Тежките далечни меши (земя, терен,
        // дървета, OSM сгради) не хвърлят: тяхната сянка не се вижда, а струва.
        // Сенчестият pass рисува декора всеки кадър (frustumCulled=false), но
        // общата му геометрия е десетки хиляди триъгълника — поносимо.
        this.scene.traverse((o) => {
            if (o.isMesh) {
                o.receiveShadow = true;
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
        this.ghost = this.#loadGhost();
        this.ghostRig = buildGhostRig();
        this.ghostRig.root.visible = false;
        this.scene.add(this.ghostRig.root);
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

        this.running = true;
        this.started = true;
        this.lastFrame = performance.now();
        this.playerRace = { laps: 0, lastProgress: this.sim.lastProgress };
        this.sound.start();
        this.onLaunch(this.launch ? 0 : null);
        this.rafId = requestAnimationFrame(this.#frame);
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
        this.#gridOpponents();
        this.#gridPlayer();
        this.#armLaunch();
        this.playerRace = { laps: 0, lastProgress: this.sim.lastProgress };
        this.#placeCameraBehindCar();
    }

    /**
     * Конфигурира AI съперниците (вика се от pre-start екрана, преди start()).
     *
     * Нарочно БЕЗ колизии: съперник, който може да те избута, би направил
     * сървърното преиграване на твоята обиколка нечестно (валидаторът не знае
     * за тях). Твоят хронометър остава чист time trial; „състезанието" е
     * позицията срещу полето + трафикът на пистата. Близо до теб съперникът
     * изсветлява — знакът, че минаването един през друг е правило, не бъг.
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
            sim.recoverToStartEnabled = false;

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
                slotJitter: rand() * 0.5,
                laps: 0,
                lastProgress: 0,
                prevX: 0,
                prevZ: 0,
                prevHeading: 0,
                opacity: 1,
                _render: {},
            });
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
        const applyTo = (name, dir, repeat) => Promise.all([
            load(`/game-textures/${dir}/diff.jpg`, true, repeat),
            load(`/game-textures/${dir}/nor.jpg`, false, repeat),
            load(`/game-textures/${dir}/rough.jpg`, false, repeat),
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
        const elevation = atmosphere.sunElevation;
        const azimuth = atmosphere.sunAzimuth;
        const phi = THREE.MathUtils.degToRad(90 - elevation);
        const theta = THREE.MathUtils.degToRad(azimuth);
        const sunDir = new THREE.Vector3().setFromSphericalCoords(1, phi, theta);

        // Атмосферно небе (Rayleigh/Mie). Рендираме го в кубмап → фон (винаги на
        // хоризонта, независимо от позицията) + environment map за IBL.
        const sky = new Sky();
        sky.scale.setScalar(10000);
        const u = sky.material.uniforms;
        u.turbidity.value = 6;
        u.rayleigh.value = 2.2;
        u.mieCoefficient.value = 0.005;
        u.mieDirectionalG.value = 0.8;
        u.sunPosition.value.copy(sunDir);

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
        // се приложи по-късно или изобщо липсва.
        this.scene.environmentIntensity = 0.6;
        this.cubeRT = cubeRT;
        pmrem.dispose();
        sky.geometry.dispose();
        sky.material.dispose();

        // Мека околна светлина + ключова слънчева със сенки.
        // Намалена — HDRI-то вече дава небесен fill, затова аналитичната е по-слаба.
        this.scene.add(new THREE.HemisphereLight(0xbfd8ff, 0x33402f, 0.75));

        const sun = new THREE.DirectionalLight(atmosphere.sunColor, atmosphere.sunIntensity);
        sun.castShadow = true;
        sun.shadow.mapSize.set(1024, 1024); // върху стегнатата кутия (виж s) 1024 е остро
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
        const w = this.canvas.clientWidth || 1;
        const h = this.canvas.clientHeight || 1;

        this.composer = new EffectComposer(this.renderer);
        this.composer.addPass(new RenderPass(this.scene, this.camera));

        // Bloom е скъп (~11 fullscreen прохода — mip верига): само на десктоп.
        // GTAO остава изключено нарочно (на full-res сваляше кадрите).
        if (!isLowPowerDevice()) {
            // Само ярките акценти греят: слънчеви отблясъци по clearcoat боята и
            // яркото HDRI небе. Висок threshold + умерена сила.
            this.composer.addPass(new UnrealBloomPass(new THREE.Vector2(w, h), 0.1, 0.5, 1.0));
        }

        // Broadcast грейд — един евтин fullscreen проход, на всички устройства.
        this.composer.addPass(new ShaderPass(GRADE_SHADER));

        // SMAA винаги — евтин AA (3 прохода) и ЕДИНСТВЕНИЯТ тук: composer-ът
        // рендерира в собствен offscreen target, тъй че antialias:true на контекста
        // не важи. На слабо устройство печелим от пропуснатия bloom, но пазим ръбовете.
        this.composer.addPass(new SMAAPass());

        // Финал: tone mapping (ACES от рендера) + sRGB към екрана. При composer
        // рендерът е линеен до OutputPass, затова няма двойно tone mapping.
        this.composer.addPass(new OutputPass());
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
        this.composer.render();
        this.composer.render();
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
     * Пази новия рекорден дух (тихо — квотата на localStorage не е гарантирана).
     *
     * @param {Float32Array} frames
     * @param {number} lapTicks
     */
    #saveGhost(frames, lapTicks) {
        // Духът в паметта се обновява ВИНАГИ — квотата на localStorage може
        // да провали само персистирането, не тазсесийния съперник.
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

            if (event.code === 'KeyR') {
                this.reset(true);
            }

            // C превключва chase ↔ бордова (halo) камера (не и в ТВ реплей).
            if (event.code === 'KeyC' && !event.repeat && !this.replay) {
                this.cameraMode = this.cameraMode === 'chase' ? 'onboard' : 'chase';
                this.halo.visible = this.cameraMode === 'onboard';
                this.lookTarget = null; // погледът да не замахне от старата точка
            }

            // M заглушава/пуска звука.
            if (event.code === 'KeyM' && !event.repeat) {
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
        if (event.frames) {
            this.lastLapFrames = event.frames;

            if (
                event.valid &&
                (this.ghost === null || event.lapTicks < this.ghost.lapTicks)
            ) {
                this.#saveGhost(event.frames, event.lapTicks);
            }
        }

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

        const dt = Math.min((now - this.lastFrame) / 1000, MAX_FRAME_TIME);
        this.lastFrame = now;

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
            // симулация — физиката на играча не ги вижда.
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

            this.accumulator -= FIXED_DT;
        }

        // Изминат път за позицията П1..Пn — праговете хващат и пресичане на
        // линията, и връщане назад (телепорт от recovery през линията).
        trackWrap(this.playerRace, sim.lastProgress);
        for (const opp of this.opponents) {
            trackWrap(opp, opp.sim.lastProgress);
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

        // Съперниците: интерполация като при играча + изсветляване наблизо.
        this.#updateOpponents(alpha, dt, render);

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
        updateDrivetrain(this.drivetrain, state.vForward, this.input.throttle);

        // Звукът следва реалните обороти + повърхността под колата.
        this.sound.update(this.drivetrain.rpm, this.input.throttle, {
            kerb: sim.onKerb,
            gravel: sim.offSurface === 'gravel',
            speed: Math.abs(state.vForward),
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

        this.composer.render();

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
        if (this.opponents.length > 0) {
            const race = this.playerRace;
            const covered = race.laps + race.lastProgress;
            for (const opp of this.opponents) {
                if (opp.laps + opp.lastProgress > covered) {
                    position++;
                }
            }
        }

        this.onTelemetry({
            speed: Math.round(speedKmh(state)),
            rpm: Math.round(this.drivetrain.rpm),
            gear: this.drivetrain.gear,
            position,
            fieldSize: this.opponents.length + 1,
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
            recoverRestart: sim.recovering && sim.recoverToStart,
            gated: sim.phase === 'flying' && sim.timerGated,
            warnings: sim.warnings,
            maxWarnings: MAX_WARNINGS,
        });
    };

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

            opp.opacity = 1;
            for (const material of opp.rig.materials) {
                material.opacity = 1;
            }
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
                ? { elapsed: 0, hold: 0.6 + Math.random() * 0.9 }
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

        if (launch.elapsed >= outAt) {
            // Гаснат — и потегляме.
            if (this.startLights) {
                for (const material of this.startLights) {
                    material.emissiveIntensity = 0;
                }
            }
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
        this.composer.render();
    }

    /**
     * Рендер на съперниците: същата интерполация между стъпките като при
     * играча + изсветляване при близост (сигналът „няма колизии").
     *
     * @param {number} alpha Остатък от акумулатора, [0..1)
     * @param {number} dt
     * @param {object} playerRender Интерполираното състояние на играча
     */
    #updateOpponents(alpha, dt, playerRender) {
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

            const distance = Math.hypot(render.x - playerRender.x, render.z - playerRender.z);
            const opacity =
                distance < RIVAL_FADE_DISTANCE
                    ? 0.35 + (distance / RIVAL_FADE_DISTANCE) * 0.65
                    : 1;

            if (Math.abs(opacity - opp.opacity) > 0.01) {
                opp.opacity = opacity;
                for (const material of opp.rig.materials) {
                    material.opacity = opacity;
                }
            }
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

        this.sound.updateRival(nearest, speed, pan);
    }

    /**
     * Духът: интерполира кадрите на рекордната обиколка спрямо ТЕКУЩИЯ
     * хронометър — истинска задочна битка, паузите (гейт) спират и двамата.
     */
    #updateGhost() {
        const sim = this.sim;
        const ghost = this.ghost;

        if (!ghost || sim.phase !== 'flying') {
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

        this.composer.render();
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
    const ghostTint = new THREE.Color(0x9fc8ff);

    rig.root.traverse((object) => {
        if (object.isMesh) {
            const material = object.material.clone();
            material.transparent = true;
            material.opacity = 0.35;
            material.depthWrite = false;
            // Призрачно-син тон — да не се бърка с истинската кола.
            material.color?.lerp?.(ghostTint, 0.7);
            object.material = material;
            object.castShadow = false;
        }
    });

    return rig;
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
            // Винаги transparent: превключването on/off рекомпилира шейдъра.
            material.transparent = true;
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
