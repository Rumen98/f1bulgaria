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
import { SMAAPass } from 'three/addons/postprocessing/SMAAPass.js';
import { UnrealBloomPass } from 'three/addons/postprocessing/UnrealBloomPass.js';
import { buildCar, updateCarRig, attachCarModel } from './car.js';
import { buildTrackMeshes, COLORS } from './mesh.js';
import { CAR, FIXED_DT, createCarState, speedKmh, step } from './physics.js';
import { prepareTrack, projectOnTrack } from './track.js';

/** Брой сектори на обиколка, както в истинската Формула 1. */
const SECTORS = 3;

/** Продължителност на връщането на пистата (брояч 3-2-1), в тикове. */
const RECOVER_TICKS = 360; // 3 s при 120 Hz

/** Колко дълго извън пистата, преди да пуснем връщане — за да не се задейства
 *  при кратко клъцване на бордюр/тревата. */
const OFFTRACK_GRACE = 48; // 0.4 s

/** Позволени излизания в квалификационната, преди времето да се изтрие. */
const MAX_WARNINGS = 3;

/** Колко метра ПРЕДИ мястото на излизане да върнем колата — за да има място да
 *  се насочи и да не излети пак в средата на завоя. */
const RECOVER_LOOKBACK = 25;

/** Дял от скоростта, с който пускаме колата след връщане (намалена скорост). */
const RECOVER_SPEED_FACTOR = 0.6;

/** Максимално време, което един кадър може да добави — спира спиралата на смъртта. */
const MAX_FRAME_TIME = 0.25;

const CAMERA = {
    distance: 9.5,
    height: 3.6,
    lookAhead: 12,
    /** Field of view при покой и при максимална скорост. */
    fovIdle: 62,
    fovFast: 84,
    /** Колко бързо камерата догонва колата. По-високо = по-залепена. */
    followDamping: 7.5,
};

export class Game {
    /**
     * @param {HTMLCanvasElement} canvas
     * @param {object} trackData Съдържанието на {slug}.json
     * @param {(telemetry: object) => void} onTelemetry
     * @param {(result: object) => void} [onFinish] Извиква се веднъж при
     *        завършена квалификационна обиколка (за резултатния екран).
     */
    constructor(canvas, trackData, onTelemetry, onFinish = () => {}) {
        this.canvas = canvas;
        this.onTelemetry = onTelemetry;
        this.onFinish = onFinish;
        this.track = prepareTrack(trackData);

        this.renderer = new THREE.WebGLRenderer({
            canvas,
            antialias: true,
            powerPreference: 'high-performance',
        });
        // Над 2 нищо не се печели визуално, а на телефон убива кадрите.
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));

        // Филмов tone mapping + сенки (Фаза 1 реализъм).
        this.renderer.toneMapping = THREE.ACESFilmicToneMapping;
        this.renderer.toneMappingExposure = 0.95;
        this.renderer.shadowMap.enabled = true;
        this.renderer.shadowMap.type = THREE.PCFSoftShadowMap;

        this.scene = new THREE.Scene();
        this.scene.background = new THREE.Color(COLORS.sky); // сменя се от небето по-долу
        this.scene.fog = new THREE.Fog(0xbcd3e6, 300, 1100);

        this.camera = new THREE.PerspectiveCamera(CAMERA.fovIdle, 1, 0.5, 2200);

        this.#setupEnvironment();
        this.scene.add(buildTrackMeshes(this.track));

        this.carRig = buildCar();
        this.scene.add(this.carRig.root);
        // По избор: външен GLB болид (public/game-models/car.glb). Липсва ли —
        // остава процедурният силует по-горе.
        attachCarModel(this.carRig);

        // Сенки: всеки mesh хвърля и приема.
        this.scene.traverse((o) => {
            if (o.isMesh) {
                o.castShadow = true;
                o.receiveShadow = true;
            }
        });

        this.state = createCarState(this.track);
        this.input = { throttle: 0, brake: 0, steer: 0 };
        this.keys = new Set();
        this.touch = { throttle: 0, brake: 0, steer: 0 };

        // Асфалтът под колата: височина за рендера, наклон за гравитацията.
        this.surface = { height: this.track.ys[0], gradient: this.track.gradient[0] };

        this.trackIndexHint = null;
        this.accumulator = 0;
        this.lastFrame = 0;
        this.running = false;
        this.rafId = null;

        this.#resetLapState();
        this.bestLapTicks = null;
        this.lastLapTicks = null;
        this.lastSectors = [null, null, null]; // секторни разбивки на последната обиколка

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
        this.lastFrame = performance.now();
        this.rafId = requestAnimationFrame(this.#frame);
    }

    /** Спира цикъла, без да освобождава ресурси. */
    stop() {
        this.running = false;
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
        this.state = createCarState(this.track);
        this.surface = { height: this.track.ys[0], gradient: this.track.gradient[0] };
        this.trackIndexHint = null;
        this.accumulator = 0;
        this.#resetLapState();

        if (!keepRecords) {
            this.bestLapTicks = null;
            this.lastLapTicks = null;
            this.lastSectors = [null, null, null];
        }

        this.#placeCameraBehindCar();
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

    /** Освобождава WebGL ресурсите. Задължително при unmount. */
    dispose() {
        this.stop();
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
                    material.dispose();
                }
            }
        });

        this.composer?.dispose();
        this.cubeRT?.dispose();
        this.envRT?.dispose();
        this.renderer.dispose();
    }

    // ── Вътрешни ─────────────────────────────────────────────────────────

    #setupEnvironment() {
        // Посока на слънцето от азимут/елевация. Ниско слънце = дълги сенки.
        const elevation = 32;
        const azimuth = 130;
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
        this.cubeRT = cubeRT;
        pmrem.dispose();
        sky.geometry.dispose();
        sky.material.dispose();

        // Мека околна светлина + ключова слънчева със сенки.
        // Намалена — HDRI-то вече дава небесен fill, затова аналитичната е по-слаба.
        this.scene.add(new THREE.HemisphereLight(0xbfd8ff, 0x33402f, 0.75));

        const sun = new THREE.DirectionalLight(0xfff2d8, 2.6);
        sun.castShadow = true;
        sun.shadow.mapSize.set(2048, 2048);
        sun.shadow.bias = -0.0004;
        sun.shadow.normalBias = 0.6;
        const s = 170; // половин размер на сенчестата зона около колата, m
        Object.assign(sun.shadow.camera, { left: -s, right: s, top: s, bottom: -s, near: 1, far: 900 });
        sun.shadow.camera.updateProjectionMatrix();
        this.scene.add(sun);
        this.scene.add(sun.target);

        this.sun = sun;
        this.sunDir = sunDir;

        // ── Фаза 2: истински HDRI за околната среда ──────────────────────────
        // Отраженията по clearcoat боята и по мокрия асфалт идват от снимано
        // небе (Poly Haven CC0), не от процедурното — оттам „реалният" вид.
        // Зарежда се асинхронно с процедурното небе като fallback, за да не
        // блокира старта; PMREM се смята веднъж → нулев per-frame разход.
        new RGBELoader().load('/game-hdri/sky_2k.hdr', (hdr) => {
            hdr.mapping = THREE.EquirectangularReflectionMapping;
            const pmrem = new THREE.PMREMGenerator(this.renderer);
            const envRT = pmrem.fromEquirectangular(hdr);
            pmrem.dispose();

            this.envRT?.dispose?.();       // старият env (от процедурното небе)
            this.envRT = envRT;
            this.scene.environment = envRT.texture;
            // Осветлението/отраженията от HDRI-то са с намалена сила (чистото небе
            // е ярко), но фонът остава пълно ярък — небето изглежда добре.
            this.scene.environmentIntensity = 0.6;
            this.scene.background = hdr;   // видимото небе = HDRI → отраженията съвпадат с гледката
        });
    }

    #setupComposer() {
        const w = this.canvas.clientWidth || 1;
        const h = this.canvas.clientHeight || 1;

        this.composer = new EffectComposer(this.renderer);
        this.composer.addPass(new RenderPass(this.scene, this.camera));

        // Bloom (Фаза 2) — само ярките акценти греят: слънчеви отблясъци по
        // clearcoat боята и яркото HDRI небе. Висок threshold + умерена сила =
        // кинематографичен блясък без „млечен" екран. Евтин на тази резолюция.
        this.composer.addPass(new UnrealBloomPass(new THREE.Vector2(w, h), 0.1, 0.5, 1.0));

        // GTAO остава изключено нарочно: на full-res сваляше кадрите (вкл. телефон).

        // SMAA — чист антиалиасинг върху вече композираната картина.
        this.composer.addPass(new SMAAPass());

        // Финал: tone mapping (ACES от рендера) + sRGB към екрана. При composer
        // рендерът е линеен до OutputPass, затова няма двойно tone mapping.
        this.composer.addPass(new OutputPass());
    }

    #resetLapState() {
        this.lapTicks = 0;
        this.sectorsVisited = new Array(SECTORS).fill(false);
        this.lastProgress = 0;
        // Тайм-триал: 'formation' (загряваща, без хронометър) → 'flying'
        // (квалификационната, която пазим) → 'finished' (кариран флаг + резултат).
        this.phase = 'formation';
        this.lapValid = true;
        this.sectorTicks = new Array(SECTORS).fill(null);
        this.currentSector = 0;

        // Връщане на пистата + предупреждения.
        this.recovering = false;
        this.recoverTicks = 0;
        this.recoverToStart = false;
        this.recoverReleaseSpeed = 0;
        this.recoverResumeSpeed = 0;
        this.recoverTarget = null;
        this.offTrackTicks = 0;
        this.warnings = 0;
        // Хронометърът чака връщане на скоростта след спасяване (ограничено до
        // gateDistance метра преоткарване).
        this.timerGated = false;
        this.gateDistance = 0;
        this.safeState = this.#startSafeState(0);
        // След телепорт рендерът да не интерполира от старото място (иначе колата
        // „прелита" за един кадър).
        this.snapRender = false;
    }

    #placeCameraBehindCar() {
        const forwardX = Math.sin(this.state.heading);
        const forwardZ = Math.cos(this.state.heading);

        this.camera.position.set(
            this.state.x - forwardX * CAMERA.distance,
            this.surface.height + CAMERA.height,
            this.state.z - forwardZ * CAMERA.distance
        );
        this.camera.lookAt(this.state.x, this.surface.height + 0.6, this.state.z);
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
        };

        this.onKeyUp = (event) => {
            this.keys.delete(event.code);
        };

        // Alt-Tab по време на завой оставя клавиша „натиснат" завинаги.
        this.onBlur = () => this.keys.clear();

        window.addEventListener('keydown', this.onKeyDown);
        window.addEventListener('keyup', this.onKeyUp);
        window.addEventListener('blur', this.onBlur);
    }

    #unbindEvents() {
        window.removeEventListener('keydown', this.onKeyDown);
        window.removeEventListener('keyup', this.onKeyUp);
        window.removeEventListener('blur', this.onBlur);
    }

    #readInput() {
        const held = (...codes) => codes.some((code) => this.keys.has(code));

        const throttle = held('ArrowUp', 'KeyW') ? 1 : 0;
        const brake = held('ArrowDown', 'KeyS', 'Space') ? 1 : 0;
        const steer =
            (held('ArrowLeft', 'KeyA') ? -1 : 0) + (held('ArrowRight', 'KeyD') ? 1 : 0);

        this.input.throttle = Math.max(throttle, this.touch.throttle);
        this.input.brake = Math.max(brake, this.touch.brake);

        // Дясноориентирана three.js сцена + chase камера зад колата → физическото
        // „надясно" (+x) се РЕНДЕРИРА вляво на екрана. Обръщаме тук (клавиатура и
        // тъч наведнъж), за да съвпада натиснатата посока с видяната. Физиката
        // (physics.js) остава недокосната — тя е чиста функция за replay.
        const merged = steer !== 0 ? steer : this.touch.steer;
        this.input.steer = -merged;
    }

    #fixedStep() {
        // Връщане на пистата: колата стои на респ. точката, брои 3-2-1 — без
        // физика и без хронометър, докато тече броячът.
        if (this.recovering) {
            this.recoverTicks--;
            if (this.recoverTicks <= 0) {
                this.#completeRecovery();
            }
            return;
        }

        const projection = projectOnTrack(
            this.track,
            this.state.x,
            this.state.z,
            this.trackIndexHint
        );
        this.trackIndexHint = projection.index;
        this.surface.height = projection.height;
        this.surface.gradient = projection.gradient;

        const onTrack = Math.abs(projection.lateral) < this.track.width / 2;

        if (onTrack) {
            // Помним последното безопасно състояние — там ни връща спасяването.
            this.offTrackTicks = 0;
            this.#rememberSafeState(projection.index);
        } else {
            // Устойчиво извън пистата → пускаме връщане (иначе оставаш в тревата).
            // Не и след финала — там резултатният екран е отгоре, колата само се
            // търкаля.
            this.offTrackTicks++;
            if (this.offTrackTicks > OFFTRACK_GRACE && this.phase !== 'finished') {
                this.#beginRecovery();
                return;
            }
        }

        step(this.state, this.input, FIXED_DT, onTrack, projection.gradient);

        this.#updateLapTiming(projection, onTrack);
    }

    /**
     * Запомня осевата точка + посоката на трасето + скоростта, докато сме на
     * пистата — целта за връщане при излизане.
     *
     * @param {number} index
     */
    #rememberSafeState(index) {
        this.safeState = {
            index,
            x: this.track.xs[index],
            z: this.track.zs[index],
            heading: Math.atan2(this.track.tx[index], this.track.tz[index]),
            speed: this.state.vForward,
        };
    }

    /**
     * Безопасно състояние на осева точка `index` (по трасето, изправено).
     *
     * @param {number} index
     * @param {number} speed
     * @returns {{index: number, x: number, z: number, heading: number, speed: number}}
     */
    #safeStateAt(index, speed = 0) {
        return {
            index,
            x: this.track.xs[index],
            z: this.track.zs[index],
            heading: Math.atan2(this.track.tx[index], this.track.tz[index]),
            speed,
        };
    }

    /**
     * Безопасно състояние на стартовата линия (за рестарт на квалификационната).
     *
     * @param {number} speed
     */
    #startSafeState(speed = 0) {
        return this.#safeStateAt(0, speed);
    }

    /**
     * Пуска връщането на пистата (брояч 3-2-1). В квалификационната брои
     * предупрежденията; на третото трие времето и рестартира от старта.
     */
    #beginRecovery() {
        let toStart = false;

        if (this.phase === 'flying') {
            this.warnings++;
            if (this.warnings >= MAX_WARNINGS) {
                toStart = true;
            }
        }

        const preOffSpeed = this.safeState.speed; // скоростта преди излитането

        let target;
        if (toStart) {
            // Трето предупреждение → нова квалификационна от старта, летящ старт.
            target = this.#startSafeState(preOffSpeed);
            this.recoverReleaseSpeed = preOffSpeed;
        } else {
            // Връщаме ПРЕДИ мястото на излизане (не в средата на завоя), с
            // намалена скорост, за да има място да се насочи и да не излети пак.
            const offset = Math.round(RECOVER_LOOKBACK / this.track.spacing);
            const count = this.track.count;
            const index = (((this.safeState.index - offset) % count) + count) % count;
            target = this.#safeStateAt(index, preOffSpeed);
            this.recoverReleaseSpeed = preOffSpeed * RECOVER_SPEED_FACTOR;
        }

        this.recovering = true;
        this.recoverTicks = RECOVER_TICKS;
        this.recoverToStart = toStart;
        // Хронометърът тръгва чак като си върнеш скоростта отпреди излитането.
        this.recoverResumeSpeed = preOffSpeed;
        this.offTrackTicks = 0;

        // Позиционираме колата веднага на респ. точката (играчът вижда къде ще
        // продължи), замразена докато тече броячът.
        this.#placeCarAt(target);
    }

    /**
     * Телепортира колата на дадено безопасно състояние и я замразява.
     *
     * @param {{index: number, x: number, z: number, heading: number}} target
     */
    #placeCarAt(target) {
        this.state.x = target.x;
        this.state.z = target.z;
        this.state.heading = target.heading;
        this.state.vForward = 0;
        this.state.vLateral = 0;
        this.state.yawRate = 0;
        this.state.steer = 0;
        this.state.slip = 0;

        this.trackIndexHint = target.index;
        this.surface.height = this.track.ys[target.index];
        this.surface.gradient = this.track.gradient[target.index];
        this.recoverTarget = target;
        this.snapRender = true;

        // Камерата да не помете през картата — залепяме я зад колата на новото
        // място и пресъздаваме look-таргета.
        this.lookTarget = null;
        this.#placeCameraBehindCar();
    }

    /**
     * Край на брояча: пуска колата на записаната скорост, изправена по трасето.
     * При рестарт (трето предупреждение) започва нова квалификационна от старта.
     */
    #completeRecovery() {
        this.recovering = false;

        // Пускаме на (намалената) скорост, изправени по трасето.
        this.state.vForward = this.recoverReleaseSpeed;
        this.state.vLateral = 0;
        this.state.yawRate = 0;

        const target = this.recoverTarget;
        this.lastProgress = target.index / this.track.count;

        if (this.recoverToStart) {
            // Чиста нова квалификационна от старта — брои се веднага (летящ старт).
            // Стартовата линия е сектор 0 (target.index е 0); подаваме сектора
            // изрично, за да не се бърка индекс на трасето със сектор.
            this.phase = 'flying';
            this.warnings = 0;
            this.#armFlyingLap(0);
            this.timerGated = false;
        } else {
            // Хронометърът чака да върнеш скоростта отпреди излитането — но най-
            // много колкото преоткарването назад (RECOVER_LOOKBACK), за да е
            // ограничено и предвидимо.
            this.timerGated = true;
            this.gateDistance = RECOVER_LOOKBACK;
        }

        this.recoverToStart = false;
    }

    /**
     * @param {{lateral: number, distance: number}} projection
     * @param {boolean} onTrack
     */
    #updateLapTiming(projection, onTrack) {
        const progress = clamp01(projection.distance / this.track.length);

        // След финала хронометърът е спрян — само пазим прогреса за коректен wrap.
        if (this.phase === 'finished') {
            this.lastProgress = progress;
            return;
        }

        const sector = Math.min(SECTORS - 1, Math.floor(progress * SECTORS));

        if (this.phase === 'flying') {
            if (this.timerGated) {
                // След връщане не наказваме преоткарването назад: „безплатни" са
                // само метрите до мястото на излизане (RECOVER_LOOKBACK). Гейтът
                // се клиърва и щом върнеш скоростта отпреди (ако е по-рано). Така
                // нехронометрираната отсечка е ограничена и НЕ може да пресече
                // финала → без невъзможно бързи обиколки в класацията.
                this.gateDistance -= Math.max(0, this.state.vForward) * FIXED_DT;
                if (this.state.vForward >= this.recoverResumeSpeed || this.gateDistance <= 0) {
                    this.timerGated = false;
                }
            }
            // Излизането вече не анулира обиколката — поема го системата с
            // предупреждения (3 → изтриване + нова квалификационна).
            if (!this.timerGated) {
                this.lapTicks++;
            }
        }

        this.sectorsVisited[sector] = true;

        if (sector !== this.currentSector) {
            // Записваме сплита при ПЪРВО прекосяване напред (ако още е null) — така
            // не се презаписва при преоткарване назад след връщане, нито остава
            // null, ако границата е минала докато таймерът е чакал.
            if (
                this.phase === 'flying' &&
                sector === this.currentSector + 1 &&
                this.sectorTicks[this.currentSector] === null
            ) {
                this.sectorTicks[this.currentSector] = this.lapTicks;
            }
            this.currentSector = sector;
        }

        // Пресичане на стартовата линия напред: прогресът пада от ~1 към ~0.
        const wrappedForward = this.lastProgress > 0.85 && progress < 0.15;
        const wrappedBackward = this.lastProgress < 0.15 && progress > 0.85;

        if (wrappedForward) {
            // Гейтът никога не пресича стартовата линия — иначе финалът би
            // банкирал по-кратко време от реално каране.
            this.timerGated = false;

            if (this.phase === 'formation') {
                // Край на загряващата → започва хронометрираната обиколка.
                this.phase = 'flying';
                this.warnings = 0;
                this.#armFlyingLap(sector);
            } else if (this.phase === 'flying' && this.sectorsVisited.every(Boolean)) {
                // Пълна квалификационна обиколка → кариран флаг + резултат.
                this.#finishLap();
            } else {
                // Непълна обиколка (напр. отрязан път) — започваме хронометрирането
                // отначало, за да броим само истинска пълна обиколка.
                this.#armFlyingLap(sector);
            }
        } else if (wrappedBackward && this.phase === 'flying') {
            // Мина линията на заден ход — обиколката вече не е чиста; връщаме към
            // загряваща, следващото чисто пресичане пуска нова хронометрирана.
            this.phase = 'formation';
            this.lapTicks = 0;
        }

        this.lastProgress = progress;
    }

    /**
     * Нулира хронометъра за нова хронометрирана обиколка от текущия сектор.
     *
     * @param {number} sector
     */
    #armFlyingLap(sector) {
        this.lapTicks = 0;
        this.lapValid = true;
        this.sectorsVisited = new Array(SECTORS).fill(false);
        this.sectorsVisited[sector] = true;
        this.sectorTicks = new Array(SECTORS).fill(null);
        this.currentSector = sector;
        this.timerGated = false;
    }

    /**
     * Затваря квалификационната обиколка: пази времето, изчислява секторите и
     * подава резултата за карирания флаг + записа в класацията.
     */
    #finishLap() {
        const [s1End, s2End] = this.sectorTicks;
        const sectorTicks =
            s1End !== null && s2End !== null
                ? [s1End, s2End - s1End, this.lapTicks - s2End]
                : [null, null, null];

        this.lastLapTicks = this.lapTicks;
        this.lastSectors = sectorTicks;

        if (this.lapValid && (this.bestLapTicks === null || this.lapTicks < this.bestLapTicks)) {
            this.bestLapTicks = this.lapTicks;
        }

        this.phase = 'finished';

        const toMs = (ticks) => (ticks === null ? null : Math.round(ticks * FIXED_DT * 1000));

        // Веднъж — UI-ът показва резултата и (ако е валиден) го праща в класацията.
        this.onFinish({
            lapMs: toMs(this.lapTicks),
            sectorsMs: sectorTicks.map(toMs),
            valid: this.lapValid,
        });
    }

    /**
     * @param {number} dt
     * @param {import('./physics.js').CarState} state Интерполирано състояние за рендер
     */
    #updateCamera(dt, state) {
        const forwardX = Math.sin(state.heading);
        const forwardZ = Math.cos(state.heading);

        const targetX = state.x - forwardX * CAMERA.distance;
        const targetZ = state.z - forwardZ * CAMERA.distance;

        // Камерата виси над асфалта, не над абсолютната нула — иначе на Спа
        // потъва в хълма при изкачването и увисва в небето при спускането.
        const targetY = this.surface.height + CAMERA.height;

        // Експоненциално изглаждане — не зависи от честотата на кадрите,
        // за разлика от наивния lerp с константен коефициент.
        const k = 1 - Math.exp(-CAMERA.followDamping * dt);

        this.camera.position.x += (targetX - this.camera.position.x) * k;
        this.camera.position.z += (targetZ - this.camera.position.z) * k;
        this.camera.position.y += (targetY - this.camera.position.y) * k;

        // Погледът се насочва към височината на трасето НАПРЕД, не към тази
        // под колата: на билото това открива какво идва, вместо да опира в небе.
        const { ys, spacing, count } = this.track;
        const aheadIndex =
            this.trackIndexHint === null
                ? 0
                : (this.trackIndexHint + Math.round(CAMERA.lookAhead / spacing)) % count;

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

        // Разширяването на зрителното поле със скоростта е основният трик за
        // усещане за скорост — по-силен от самото движение.
        const speedRatio = clamp01(Math.abs(state.vForward) / CAR.maxSpeed);
        const targetFov = CAMERA.fovIdle + (CAMERA.fovFast - CAMERA.fovIdle) * speedRatio;

        if (Math.abs(this.camera.fov - targetFov) > 0.01) {
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

        this.#readInput();

        this.accumulator += dt;

        // Снапшот ПРЕДИ стъпките от този кадър. Ако не се завърти стъпка (висок
        // FPS), prev == state и рендерът стои неподвижен — без трептене.
        let prevX = this.state.x;
        let prevZ = this.state.z;
        let prevHeading = this.state.heading;

        while (this.accumulator >= FIXED_DT) {
            prevX = this.state.x;
            prevZ = this.state.z;
            prevHeading = this.state.heading;
            this.#fixedStep();
            this.accumulator -= FIXED_DT;
        }

        // След телепорт (връщане на пистата) не интерполираме от старото място —
        // иначе колата „прелита" през картата за един кадър.
        if (this.snapRender) {
            prevX = this.state.x;
            prevZ = this.state.z;
            prevHeading = this.state.heading;
            this.snapRender = false;
        }

        // Интерполация между последните две стъпки. Физиката тиктака на 120 Hz,
        // но кадрите идват на променлива честота — без това колата и камерата
        // подскачат/дърпат, особено щом FPS-ът се разклати. alpha е остатъкът от
        // акумулатора: 0 = точно на стъпка, →1 = почти на следващата.
        const alpha = this.accumulator / FIXED_DT;
        let dHeading = this.state.heading - prevHeading;
        // Пази срещу евентуален wrap на heading в [-π, π].
        if (dHeading > Math.PI) dHeading -= 2 * Math.PI;
        else if (dHeading < -Math.PI) dHeading += 2 * Math.PI;

        const render = {
            ...this.state,
            x: prevX + (this.state.x - prevX) * alpha,
            z: prevZ + (this.state.z - prevZ) * alpha,
            heading: prevHeading + dHeading * alpha,
        };

        updateCarRig(this.carRig, render, this.surface, dt);
        this.#updateCamera(dt, render);

        // Сенчестата камера следва колата: посоката на слънцето е фиксирана,
        // движим само центъра, за да е острата сянка около играча.
        this.sun.target.position.set(render.x, this.surface.height, render.z);
        this.sun.position.set(
            render.x + this.sunDir.x * 300,
            this.surface.height + this.sunDir.y * 300,
            render.z + this.sunDir.z * 300
        );

        this.composer.render();

        this.onTelemetry({
            speed: Math.round(speedKmh(this.state)),
            lapTime: this.phase === 'flying' ? this.lapTicks * FIXED_DT : null,
            lastLap: this.lastLapTicks === null ? null : this.lastLapTicks * FIXED_DT,
            bestLap: this.bestLapTicks === null ? null : this.bestLapTicks * FIXED_DT,
            sector: this.currentSector + 1,
            sectors: this.lastSectors.map((t) => (t === null ? null : t * FIXED_DT)),
            lapValid: this.lapValid,
            started: this.phase === 'flying',
            phase: this.phase,
            recovering: this.recovering,
            recoverCount: this.recovering ? Math.ceil(this.recoverTicks / 120) : 0,
            recoverRestart: this.recovering && this.recoverToStart,
            gated: this.phase === 'flying' && this.timerGated,
            warnings: this.warnings,
            maxWarnings: MAX_WARNINGS,
        });
    };
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
 * @param {number} v
 * @returns {number}
 */
function clamp01(v) {
    return v < 0 ? 0 : v > 1 ? 1 : v;
}
