/**
 * Оркестратор на играта: сцена, камера, вход, цикъл, хронометър.
 *
 * Времето се брои в стъпки на симулацията, НЕ по стенен часовник. Освен че е
 * коректно (кадрите се колебаят, стъпките не), това е предпоставката за
 * сървърна валидация по-късно: обиколка = брой стъпки × FIXED_DT.
 */

import * as THREE from 'three';
import { buildCar, updateCarRig } from './car.js';
import { buildTrackMeshes, COLORS } from './mesh.js';
import { CAR, FIXED_DT, createCarState, speedKmh, step } from './physics.js';
import { prepareTrack, projectOnTrack } from './track.js';

/** Брой сектори на обиколка, както в истинската Формула 1. */
const SECTORS = 3;

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
     */
    constructor(canvas, trackData, onTelemetry) {
        this.canvas = canvas;
        this.onTelemetry = onTelemetry;
        this.track = prepareTrack(trackData);

        this.renderer = new THREE.WebGLRenderer({
            canvas,
            antialias: true,
            powerPreference: 'high-performance',
        });
        // Над 2 нищо не се печели визуално, а на телефон убива кадрите.
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));

        this.scene = new THREE.Scene();
        this.scene.background = new THREE.Color(COLORS.sky);
        this.scene.fog = new THREE.Fog(COLORS.sky, 220, 850);

        this.camera = new THREE.PerspectiveCamera(CAMERA.fovIdle, 1, 0.5, 2200);

        this.#setupLights();
        this.scene.add(buildTrackMeshes(this.track));

        this.carRig = buildCar();
        this.scene.add(this.carRig.root);

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

        this.#placeCameraBehindCar();
        this.#bindEvents();
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

        this.renderer.dispose();
    }

    // ── Вътрешни ─────────────────────────────────────────────────────────

    #setupLights() {
        this.scene.add(new THREE.HemisphereLight(0xdcefff, 0x2c3a2c, 1.5));

        const sun = new THREE.DirectionalLight(0xfff3e0, 1.6);
        sun.position.set(-320, 480, 210);
        this.scene.add(sun);
    }

    #resetLapState() {
        this.lapTicks = 0;
        this.sectorsVisited = new Array(SECTORS).fill(false);
        this.lastProgress = 0;
        this.lapStarted = false;
        this.lapValid = true;
        this.sectorTicks = new Array(SECTORS).fill(null);
        this.currentSector = 0;
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
        this.input.steer = steer !== 0 ? steer : this.touch.steer;
    }

    #fixedStep() {
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

        step(this.state, this.input, FIXED_DT, onTrack, projection.gradient);

        this.#updateLapTiming(projection, onTrack);
    }

    /**
     * @param {{lateral: number, distance: number}} projection
     * @param {boolean} onTrack
     */
    #updateLapTiming(projection, onTrack) {
        const progress = clamp01(projection.distance / this.track.length);
        const sector = Math.min(SECTORS - 1, Math.floor(progress * SECTORS));

        if (this.lapStarted) {
            this.lapTicks++;

            if (!onTrack) {
                // Излизането извън трасето вече е наказано физически. Флагът е
                // за HUD-а — анулирането на времена е решение за класацията,
                // не за прототипа.
                this.lapValid = false;
            }
        }

        this.sectorsVisited[sector] = true;

        if (sector !== this.currentSector) {
            if (this.lapStarted && sector === this.currentSector + 1) {
                this.sectorTicks[this.currentSector] = this.lapTicks;
            }
            this.currentSector = sector;
        }

        // Пресичане на стартовата линия напред: прогресът пада от ~1 към ~0.
        const wrappedForward = this.lastProgress > 0.85 && progress < 0.15;
        const wrappedBackward = this.lastProgress < 0.15 && progress > 0.85;

        if (wrappedForward) {
            if (this.lapStarted && this.sectorsVisited.every(Boolean)) {
                this.lastLapTicks = this.lapTicks;

                if (this.lapValid && (this.bestLapTicks === null || this.lapTicks < this.bestLapTicks)) {
                    this.bestLapTicks = this.lapTicks;
                }
            }

            this.lapTicks = 0;
            this.lapValid = true;
            this.lapStarted = true;
            this.sectorsVisited = new Array(SECTORS).fill(false);
            this.sectorsVisited[sector] = true;
            this.sectorTicks = new Array(SECTORS).fill(null);
            this.currentSector = sector;
        } else if (wrappedBackward) {
            // Мина линията на заден ход — обиколката вече не е чиста.
            this.lapStarted = false;
            this.lapTicks = 0;
        }

        this.lastProgress = progress;
    }

    /**
     * @param {number} dt
     */
    #updateCamera(dt) {
        const forwardX = Math.sin(this.state.heading);
        const forwardZ = Math.cos(this.state.heading);

        const targetX = this.state.x - forwardX * CAMERA.distance;
        const targetZ = this.state.z - forwardZ * CAMERA.distance;

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

        this.camera.lookAt(
            this.state.x + forwardX * CAMERA.lookAhead,
            ys[aheadIndex] + 0.9,
            this.state.z + forwardZ * CAMERA.lookAhead
        );

        // Разширяването на зрителното поле със скоростта е основният трик за
        // усещане за скорост — по-силен от самото движение.
        const speedRatio = clamp01(Math.abs(this.state.vForward) / CAR.maxSpeed);
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
        while (this.accumulator >= FIXED_DT) {
            this.#fixedStep();
            this.accumulator -= FIXED_DT;
        }

        updateCarRig(this.carRig, this.state, this.surface, dt);
        this.#updateCamera(dt);
        this.renderer.render(this.scene, this.camera);

        this.onTelemetry({
            speed: Math.round(speedKmh(this.state)),
            lapTime: this.lapStarted ? this.lapTicks * FIXED_DT : null,
            lastLap: this.lastLapTicks === null ? null : this.lastLapTicks * FIXED_DT,
            bestLap: this.bestLapTicks === null ? null : this.bestLapTicks * FIXED_DT,
            sector: this.currentSector + 1,
            lapValid: this.lapValid,
            started: this.lapStarted,
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
