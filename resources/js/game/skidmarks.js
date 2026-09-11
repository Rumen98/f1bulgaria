/**
 * Следи от гуми: пистата помни къде е плъзгал ВСЕКИ болид — играчът, шестте
 * съперника и реплей колата.
 *
 * Един ring buffer от предварително заделени quad-ове в една BufferGeometry
 * (един draw call за всички коли). Всяка кола има „писач" (SkidWriter) с
 * четири ленти — две задни (плъзгане/буксуване) и две предни (lock-up,
 * подзавиване). Лентата е непрекъсната лента от quad-ове със споделени ръбове
 * (без прорези по дъгите), стеснена в началото и в края, с alpha по силата на
 * плъзгането и с надлъжни бразди от процедурен alphaMap. Най-старите quad-ове
 * гаснат постепенно, преди ring-ът да ги превърти — не изчезват с „поп".
 *
 * Височина: surface.height е под ЦЕНТЪРА на колата; точката на колелото се
 * коригира по наклона (задната ос на Eau Rouge е 0.33 m по-ниско) и по
 * банкинга — иначе следите висят във въздуха на изкачване и потъват под
 * асфалта на спускане, където depthTest ги крие. polygonOffset ги държи
 * видими и върху кербовете (2 cm над асфалта) без да ги вдига над пътя.
 *
 * Чисто презентационен модул: чете sim state, никога не го пипа.
 *
 * Публично:
 *   new SkidMarks(scene, { lowPower, quality })
 *   marks.createWriter(rig, emitter)   → писач за една кола (emitter от particles.js споделя сигналите)
 *   writer.write(dt, state, surface, sim, input, out)
 *   marks.update(dt, camera)           → позиция на камерата за cull, веднъж на кадър
 *   marks.setWet(bool)                 → наполовина по-бледи следи
 *   marks.dispose()
 *   LEGACY: marks.update(laying, render, groundY, bank) — старият еднокаров вход.
 */

import * as THREE from 'three';
import { getNoiseTexture } from './noiseTex.js';
import { DEFAULT_AXLES, createTyreSignals, updateTyreSignals, sampleNoiseData } from './particles.js';

/** Максимален брой quad-ове (4 ленти × до 8 коли; ≈ 6+ обиколки следи на играча). */
const MAX_QUADS = 1800;

/** Височина над асфалта — над боядосаната линия (0.006). */
const LIFT = 0.01;

/** На керб (0.02) следата ляга върху него; polygonOffset покрива междинните случаи. */
const LIFT_KERB = 0.024;

/** Толкова от най-старите quad-ове гаснат линейно преди да бъдат презаписани. */
const FADE_QUADS = 60;

/** Стесняване в началото и в края на всяка лента. */
const TAPER_QUADS = 3;

/** Под тази дължина (m) сегментът само трупа z-fighting; над горната е телепорт. */
const MIN_SEGMENT = 0.02;
const MAX_SEGMENT = 6;

/** Коли по-далеч от камерата не пишат — ring-ът е общ и е за това, което се вижда. */
const CULL_DISTANCE = 150;

/** alphaMap: напречно × надлъжно, повтаря се на всеки TEX_PERIOD метра. */
const TEX_ACROSS = 64;
const TEX_ALONG = 256;
const TEX_PERIOD = 1.0;

/** Цвят на гумата (линеен). */
const RUBBER_R = 0.03;
const RUBBER_G = 0.03;
const RUBBER_B = 0.035;

/** Резерв за ширината на гумите, когато rig-ът не я носи. */
const DEFAULT_TYRE_WIDTH = Object.freeze({ front: 0.3, rear: 0.38 });

function clamp(value, min, max) {
    return value < min ? min : value > max ? max : value;
}

function smoothstep(e0, e1, x) {
    const t = clamp((x - e0) / (e1 - e0), 0, 1);
    return t * t * (3 - 2 * t);
}

/**
 * Надлъжни бразди: мек напречен профил × надлъжен шум (браздите се менят
 * бавно по v, бързо по u) × лека петнистост. Тайлва се по v (шумът е
 * 256-периодичен и v минава точно 1 и 2 периода). Чисто аритметично — без
 * canvas, за да се зарежда и в node.
 *
 * @returns {THREE.DataTexture}
 */
function buildStreakMap() {
    const noise = getNoiseTexture(256);
    const nd = noise.image.data;
    const ns = noise.image.width;
    const data = new Uint8Array(TEX_ACROSS * TEX_ALONG * 4);

    for (let v = 0; v < TEX_ALONG; v++) {
        for (let u = 0; u < TEX_ACROSS; u++) {
            const fu = (u + 0.5) / TEX_ACROSS;
            const edge = smoothstep(0, 0.14, fu) * smoothstep(1, 0.86, fu);
            const grooves = 0.5 + 0.5 * sampleNoiseData(nd, ns, fu * 90, v, 0);
            const fine = 0.8 + 0.2 * sampleNoiseData(nd, ns, fu * 300 + 70, v * 2, 1);
            const patch = 0.85 + 0.15 * sampleNoiseData(nd, ns, fu * 20 + 130, v, 2);
            const a = Math.round(clamp(edge * grooves * fine * patch * 1.25, 0, 1) * 255);
            const i = (v * TEX_ACROSS + u) << 2;
            data[i] = a;
            data[i + 1] = a;
            data[i + 2] = a;
            data[i + 3] = 255;
        }
    }

    const texture = new THREE.DataTexture(data, TEX_ACROSS, TEX_ALONG, THREE.RGBAFormat, THREE.UnsignedByteType);
    texture.colorSpace = THREE.NoColorSpace;
    texture.wrapS = THREE.ClampToEdgeWrapping;
    texture.wrapT = THREE.RepeatWrapping;
    texture.minFilter = THREE.LinearMipmapLinearFilter;
    texture.magFilter = THREE.LinearFilter;
    texture.generateMipmaps = true;
    texture.needsUpdate = true;
    return texture;
}

/**
 * Една лента (едно колело): къде свърши последният quad, за да продължи от
 * същия ръб, и кои бяха последните quad-ове, за да се стеснят при край.
 *
 * @typedef {object} SkidLane
 * @property {'front'|'rear'} axle
 * @property {number} side −1|1 — локалният x на колелото (+x = ляво)
 * @property {boolean} active
 * @property {number} quads Quad-ове в текущата лента
 * @property {number} along Изминати метри в лентата (uv.v)
 * @property {number} cx @property {number} cy @property {number} cz Последен център
 * @property {number} ax @property {number} ay @property {number} az Последен ръб A
 * @property {number} bx @property {number} by @property {number} bz Последен ръб B
 * @property {Int32Array} recent Последните TAPER_QUADS индекса (ring, най-новият в recentHead−1)
 * @property {number} recentHead
 * @property {number} recentCount
 */

/**
 * @param {'front'|'rear'} axle
 * @param {number} side
 * @returns {SkidLane}
 */
function createLane(axle, side) {
    return {
        axle,
        side,
        active: false,
        quads: 0,
        along: 0,
        cx: 0, cy: 0, cz: 0,
        ax: 0, ay: 0, az: 0,
        bx: 0, by: 0, bz: 0,
        recent: new Int32Array(TAPER_QUADS),
        recentHead: 0,
        recentCount: 0,
    };
}

/**
 * Писач за една кола: превръща сигналите на гумите в alpha по лента.
 * Емитерът на particles.js (когато е даден) вече е сметнал сигналите и
 * lock-up-а за кадъра — четем ги, за да пушат и оставят следа едни и същи
 * колела в един и същ момент. emit() трябва да е извикан преди write().
 */
class SkidWriter {
    /**
     * @param {SkidMarks} marks
     * @param {{axles?: object, tyreWidth?: {front: number, rear: number}}|null} rig
     * @param {{sig: object, lock: number}|null} emitter
     */
    constructor(marks, rig, emitter) {
        this.marks = marks;
        // Rig-ът, не rig.axles/tyreWidth: car.js ги подменя, когато GLB-то
        // пристигне — четем ги при всяко полагане.
        this.rig = rig;
        this.emitter = emitter;
        this.sig = emitter ? emitter.sig : createTyreSignals();
        /** @type {SkidLane[]} */
        this.lanes = [createLane('rear', 1), createLane('rear', -1), createLane('front', 1), createLane('front', -1)];
    }

    /**
     * Следите на една кола за този кадър.
     *
     * @param {number} dt
     * @param {{x: number, z: number, heading: number, vForward: number, yawRate?: number, slip?: number, out?: object}} state
     *        Интерполираното състояние (render) на колата
     * @param {{height: number, gradient?: number, bank?: number}} surface sim.surface
     * @param {{onKerb?: boolean}|null} sim Симулацията (или shim)
     * @param {{brake?: number, throttle?: number}|null} [input]
     * @param {object|undefined} [out] sim.state.out (по подразбиране state.out)
     */
    write(dt, state, surface, sim, input = null, out = state.out) {
        const marks = this.marks;
        let sig;
        let lock;
        if (this.emitter) {
            sig = this.emitter.sig;
            lock = this.emitter.lock;
        } else {
            sig = updateTyreSignals(this.sig, dt, state, input, out);
            lock = sig.frontLock > 0.3 ? sig.frontLock : 0;
        }
        const speed = sig.speed;

        // Спряла кола: сегментите са нула, само z-fighting; далечна — ring-ът
        // е общ и е за това, което камерата вижда.
        let visible = speed > 3;
        if (visible && marks.hasCamera) {
            const d2 = (state.x - marks.camPos.x) ** 2 + (state.z - marks.camPos.z) ** 2;
            visible = d2 < CULL_DISTANCE * CULL_DISTANCE;
        }
        if (!visible) {
            this.end();
            return;
        }

        // Задните: плъзгане, буксуване или спиране с накъсана задница (старият
        // trigger на играта, запазен като fallback без v3 телеметрия).
        const layRear = sig.rear > 0.08 || sig.slip > 0.3 || (sig.brake > 0 && speed > 30 && sig.slip > 0.12);
        let rearAlpha = 0;
        if (layRear) {
            const intensity = Math.max(sig.slip, sig.rear);
            const braking = (sig.brake > 0.7 && sig.slip > 0.12) || (out?.lockR ?? 0) > 0.3;
            rearAlpha = clamp(0.1 + (intensity - 0.25) * 0.9, 0, 0.6) + (braking ? 0.15 : 0);
        }

        // Предните: блокиране на вътрешното (външното само при пълен lock) и
        // бледо стъргане от подзавиване на двете.
        const lockAlpha = lock > 0.3 ? clamp(0.1 + (lock - 0.25) * 0.9, 0, 0.6) + 0.15 : 0;
        const scrubAlpha = sig.understeer > 0.3 ? 0.12 * sig.understeer : 0;
        const frontInside = Math.max(lockAlpha, scrubAlpha);
        const frontOutside = Math.max(lock > 0.8 ? lockAlpha * 0.6 : 0, scrubAlpha);

        this.layLanes(state, surface, sim?.onKerb === true, rearAlpha, frontInside, frontOutside, sig.inside);
    }

    /**
     * Полага (или приключва) четирите ленти с дадените alpha стойности.
     *
     * @param {{x: number, z: number, heading: number}} state
     * @param {{height: number, gradient?: number, bank?: number}} surface
     * @param {boolean} onKerb
     * @param {number} rearAlpha
     * @param {number} frontInside
     * @param {number} frontOutside
     * @param {number} inside −1|1 — страната на вътрешното предно колело
     */
    layLanes(state, surface, onKerb, rearAlpha, frontInside, frontOutside, inside) {
        const marks = this.marks;
        const wet = marks.wet ? 0.5 : 1;
        const sin = Math.sin(state.heading);
        const cos = Math.cos(state.heading);
        const gradient = surface.gradient ?? 0;
        const bank = surface.bank ?? 0;
        const lift = onKerb ? LIFT_KERB : LIFT;
        const axles = this.rig?.axles ?? DEFAULT_AXLES;
        const tyreWidth = this.rig?.tyreWidth ?? DEFAULT_TYRE_WIDTH;

        for (const lane of this.lanes) {
            const axle = axles[lane.axle];
            let alpha;
            if (lane.axle === 'rear') {
                alpha = rearAlpha;
            } else {
                alpha = lane.side === inside ? frontInside : frontOutside;
            }
            alpha *= wet;

            // Локални (lx, lz) → световни; y по наклона на оста и банкинга (знакът
            // на банкинга: нормалата на трасето е локалното −x и лентата слиза по
            // нея, така че +x се качва — виж particles.js).
            const lx = lane.side * axle.x;
            const lz = axle.z;
            const x = state.x + sin * lz + cos * lx;
            const z = state.z + cos * lz - sin * lx;
            const y = surface.height + lift + lz * gradient + lx * bank;
            marks.lay(lane, x, y, z, alpha, tyreWidth[lane.axle] * 0.5);
        }
    }

    /** Приключва всички ленти (стеснение в края). */
    end() {
        for (const lane of this.lanes) {
            this.marks.lay(lane, 0, 0, 0, 0, 0);
        }
    }
}

export class SkidMarks {
    /**
     * @param {THREE.Scene} scene
     * @param {{lowPower?: boolean, quality?: object}} [options] Приети за еднаквост с другите модули; следите струват еднакво навсякъде (един draw call, 7200 върха), затова не се четат
     */
    constructor(scene, options = {}) {
        this.scene = scene;
        this.head = 0;
        this.wet = false;
        this.hasCamera = false;
        this.camPos = new THREE.Vector3();

        this.positions = new Float32Array(MAX_QUADS * 4 * 3);
        this.colors = new Float32Array(MAX_QUADS * 4 * 4);
        this.uvs = new Float32Array(MAX_QUADS * 4 * 2);
        // Alpha при полагане и стеснение по връх — за да се преизчисли
        // цветът при гаснене/стесняване, без да се губи оригиналът.
        this.quadAlpha = new Float32Array(MAX_QUADS);
        this.vertexFactor = new Float32Array(MAX_QUADS * 4);

        const indices = new Uint32Array(MAX_QUADS * 6);
        for (let q = 0; q < MAX_QUADS; q++) {
            const v = q * 4;
            indices.set([v, v + 1, v + 2, v + 1, v + 3, v + 2], q * 6);
        }

        const geometry = new THREE.BufferGeometry();
        // Пишат се по няколко quad-а на кадър; качват се само променените
        // диапазони (updateRanges), DynamicDraw подсказва на драйвера.
        this.positionAttr = new THREE.BufferAttribute(this.positions, 3).setUsage(THREE.DynamicDrawUsage);
        this.colorAttr = new THREE.BufferAttribute(this.colors, 4).setUsage(THREE.DynamicDrawUsage);
        this.uvAttr = new THREE.BufferAttribute(this.uvs, 2).setUsage(THREE.DynamicDrawUsage);
        geometry.setAttribute('position', this.positionAttr);
        geometry.setAttribute('color', this.colorAttr);
        geometry.setAttribute('uv', this.uvAttr);
        geometry.setIndex(new THREE.BufferAttribute(indices, 1));
        geometry.boundingSphere = new THREE.Sphere(new THREE.Vector3(), 1e6);

        this.alphaMap = buildStreakMap();
        this.mesh = new THREE.Mesh(
            geometry,
            new THREE.MeshBasicMaterial({
                vertexColors: true,
                transparent: true,
                depthWrite: false,
                alphaMap: this.alphaMap,
                // Напречната ос на колата е ОБРАТНА на нормалата на трасето при
                // движение напред → winding-ът на quad-а гледа надолу и
                // FrontSide би отрязал следата точно в основния ѝ случай.
                side: THREE.DoubleSide,
                // Депт отместване: следата печели z-теста срещу керба (1 cm над
                // нея) и срещу собствения асфалт под остър ъгъл, без LIFT да
                // расте (по-висок LIFT = следа, която плува над пътя отблизо).
                polygonOffset: true,
                polygonOffsetFactor: -2,
                polygonOffsetUnits: -2,
            })
        );
        this.mesh.frustumCulled = false;
        this.mesh.renderOrder = 1;
        scene.add(this.mesh);

        /** @type {SkidWriter[]} */
        this.writers = [];
        this._legacyWriter = null;
        this._legacySurface = { height: 0, gradient: 0, bank: 0 };
    }

    /**
     * Писач за една кола. rig.axles/tyreWidth (car-model) дават геометрията;
     * emitter (particles.js) споделя сигналите на гумите.
     *
     * @param {{axles?: object, tyreWidth?: object}|null} [rig]
     * @param {{sig: object, lock: number}|null} [emitter]
     * @returns {SkidWriter}
     */
    createWriter(rig = null, emitter = null) {
        const writer = new SkidWriter(this, rig, emitter);
        this.writers.push(writer);
        return writer;
    }

    /** @param {SkidWriter} writer */
    removeWriter(writer) {
        const i = this.writers.indexOf(writer);
        if (i >= 0) {
            writer.end();
            this.writers.splice(i, 1);
        }
    }

    /**
     * Веднъж на кадър: позицията на камерата за cull на далечните коли.
     *
     * LEGACY (до интеграцията): update(laying, render, groundY, bank) — старият
     * еднокаров вход; полага задните ленти с постоянна сила.
     *
     * @param {number|boolean} dt
     * @param {THREE.Camera|object|null} [camera]
     * @param {number} [groundY]
     * @param {number} [bank]
     */
    update(dt, camera = null, groundY, bank) {
        if (typeof dt === 'boolean') {
            this._legacyWriter ??= new SkidWriter(this, null, null);
            this._legacySurface.height = groundY;
            this._legacySurface.bank = bank ?? 0;
            this._legacyWriter.layLanes(camera, this._legacySurface, false, dt ? 0.34 : 0, 0, 0, 1);
            return;
        }
        if (camera !== null && camera.isCamera === true) {
            camera.getWorldPosition(this.camPos);
            this.hasCamera = true;
        }
    }

    /**
     * Мокра писта: гумата оставя наполовина по-бледа следа (weather-wet).
     *
     * @param {boolean} wet
     */
    setWet(wet) {
        this.wet = wet === true;
    }

    /**
     * Продължава лентата до (x, y, z) с дадената alpha (0 = край на лентата).
     * Първата точка само отваря лентата; всяка следваща добавя quad от
     * предишния ръб до новия — споделеният ръб маха прорезите по дъгите.
     *
     * @param {SkidLane} lane
     * @param {number} x @param {number} y @param {number} z
     * @param {number} alpha
     * @param {number} halfWidth
     */
    lay(lane, x, y, z, alpha, halfWidth) {
        if (alpha <= 0.01) {
            if (lane.active) {
                this.#endStroke(lane);
            }
            return;
        }

        if (!lane.active) {
            lane.active = true;
            lane.quads = 0;
            lane.along = 0;
            lane.recentCount = 0;
            lane.cx = x;
            lane.cy = y;
            lane.cz = z;
            return;
        }

        const dx = x - lane.cx;
        const dz = z - lane.cz;
        const len2 = dx * dx + dz * dz;
        if (len2 < MIN_SEGMENT * MIN_SEGMENT) {
            return;
        }
        if (len2 > MAX_SEGMENT * MAX_SEGMENT) {
            // Телепорт (рестарт, recovery, цикъл на реплея): нова лента.
            lane.active = false;
            this.lay(lane, x, y, z, alpha, halfWidth);
            return;
        }

        const len = Math.sqrt(len2);
        // Перпендикуляр на сегмента в XZ (нормалата на трасето при движение
        // по него) — ширината следва пътя на гумата, не оста на колата.
        const px = (-dz / len) * halfWidth;
        const pz = (dx / len) * halfWidth;

        if (lane.quads === 0) {
            lane.ax = lane.cx + px;
            lane.ay = lane.cy;
            lane.az = lane.cz + pz;
            lane.bx = lane.cx - px;
            lane.by = lane.cy;
            lane.bz = lane.cz - pz;
        }

        const q = this.head;
        this.head = (q + 1) % MAX_QUADS;

        const base = q * 12;
        const pos = this.positions;
        pos[base] = lane.ax;
        pos[base + 1] = lane.ay;
        pos[base + 2] = lane.az;
        pos[base + 3] = lane.bx;
        pos[base + 4] = lane.by;
        pos[base + 5] = lane.bz;
        pos[base + 6] = x + px;
        pos[base + 7] = y;
        pos[base + 8] = z + pz;
        pos[base + 9] = x - px;
        pos[base + 10] = y;
        pos[base + 11] = z - pz;
        this.positionAttr.addUpdateRange(base, 12);
        this.positionAttr.needsUpdate = true;

        const uvBase = q * 8;
        const uv = this.uvs;
        const v0 = lane.along / TEX_PERIOD;
        const v1 = (lane.along + len) / TEX_PERIOD;
        uv[uvBase] = 0;
        uv[uvBase + 1] = v0;
        uv[uvBase + 2] = 1;
        uv[uvBase + 3] = v0;
        uv[uvBase + 4] = 0;
        uv[uvBase + 5] = v1;
        uv[uvBase + 6] = 1;
        uv[uvBase + 7] = v1;
        this.uvAttr.addUpdateRange(uvBase, 8);
        this.uvAttr.needsUpdate = true;

        const colorBase = q * 16;
        const col = this.colors;
        for (let v = 0; v < 4; v++) {
            col[colorBase + v * 4] = RUBBER_R;
            col[colorBase + v * 4 + 1] = RUBBER_G;
            col[colorBase + v * 4 + 2] = RUBBER_B;
        }

        // Стеснение в началото: първите TAPER_QUADS quad-а растат от нула.
        const k = lane.quads;
        const nearFactor = Math.min(1, k / TAPER_QUADS);
        const farFactor = Math.min(1, (k + 1) / TAPER_QUADS);
        const factorBase = q * 4;
        this.vertexFactor[factorBase] = nearFactor;
        this.vertexFactor[factorBase + 1] = nearFactor;
        this.vertexFactor[factorBase + 2] = farFactor;
        this.vertexFactor[factorBase + 3] = farFactor;
        this.quadAlpha[q] = alpha;
        this.#writeAlpha(q);
        this.#fadeOldest();

        lane.along += len;
        lane.quads++;
        lane.cx = x;
        lane.cy = y;
        lane.cz = z;
        lane.ax = x + px;
        lane.ay = y;
        lane.az = z + pz;
        lane.bx = x - px;
        lane.by = y;
        lane.bz = z - pz;
        lane.recent[lane.recentHead] = q;
        lane.recentHead = (lane.recentHead + 1) % TAPER_QUADS;
        if (lane.recentCount < TAPER_QUADS) {
            lane.recentCount++;
        }
    }

    /**
     * Край на лента: последните quad-ове се стесняват към нула (най-новият
     * най-силно), вместо следата да свършва с прав ръб.
     *
     * @param {SkidLane} lane
     */
    #endStroke(lane) {
        lane.active = false;
        for (let j = 0; j < lane.recentCount; j++) {
            // j = 0 е най-новият quad.
            const q = lane.recent[(lane.recentHead - 1 - j + TAPER_QUADS * 2) % TAPER_QUADS];
            const farFactor = j / TAPER_QUADS;
            const nearFactor = (j + 1) / TAPER_QUADS;
            const factorBase = q * 4;
            this.vertexFactor[factorBase] *= nearFactor;
            this.vertexFactor[factorBase + 1] *= nearFactor;
            this.vertexFactor[factorBase + 2] *= farFactor;
            this.vertexFactor[factorBase + 3] *= farFactor;
            this.#writeAlpha(q);
        }
        lane.recentCount = 0;
    }

    /**
     * Най-старите FADE_QUADS quad-а (тези непосредствено пред head, които ще
     * бъдат презаписани следващи) гаснат линейно по отдалечеността си от него.
     * Викa се при всяко полагане, защото head се е преместил с едно.
     */
    #fadeOldest() {
        const first = Math.min(FADE_QUADS, MAX_QUADS - this.head);
        this.#fadeRange(this.head, first);
        if (first < FADE_QUADS) {
            this.#fadeRange(0, FADE_QUADS - first);
        }
    }

    /**
     * @param {number} start Първи quad
     * @param {number} count Брой quad-ове (без wrap)
     */
    #fadeRange(start, count) {
        let touched = false;
        for (let q = start; q < start + count; q++) {
            if (this.quadAlpha[q] > 0) {
                this.#fillAlpha(q);
                touched = true;
            }
        }
        if (touched) {
            this.colorAttr.addUpdateRange(start * 16, count * 16);
            this.colorAttr.needsUpdate = true;
        }
    }

    /**
     * Alpha на четирите върха: положената × стеснението × гасненето по
     * възраст. Без update range — за групови записи (#fadeRange).
     *
     * @param {number} q
     */
    #fillAlpha(q) {
        const age = (q - this.head + MAX_QUADS) % MAX_QUADS;
        const fade = Math.min(1, age / FADE_QUADS);
        const a = this.quadAlpha[q] * fade;
        const colorBase = q * 16;
        const factorBase = q * 4;
        for (let v = 0; v < 4; v++) {
            this.colors[colorBase + v * 4 + 3] = a * this.vertexFactor[factorBase + v];
        }
    }

    /** @param {number} q */
    #writeAlpha(q) {
        this.#fillAlpha(q);
        this.colorAttr.addUpdateRange(q * 16, 16);
        this.colorAttr.needsUpdate = true;
    }

    dispose() {
        for (const writer of this.writers) {
            writer.end();
        }
        this.writers.length = 0;
        this.scene.remove(this.mesh);
        this.mesh.geometry.dispose();
        this.mesh.material.dispose();
        this.alphaMap.dispose();
    }
}
