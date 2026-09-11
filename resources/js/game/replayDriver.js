/**
 * Реплей драйвер: от записаните 60 Hz кадри [x, z, heading] възстановява
 * ИСТИНСКАТА кинематика на болида — скорост, странична скорост, yaw rate,
 * спиране, плъзгане, склон/банкинг под колата, керб/чакъл — и кара рига с
 * нея.
 *
 * Защо: досега ТВ колата караше с фалшиви 40 m/s (колелата се въртяха еднакво
 * в хеърпина и на правата), а пичът/кренът гаснеха до нула. Скоростта е в
 * кадрите (Δпозиция / период), склонът и банкингът — в трасето под тях. С
 * реалните числа updateCarRig, частиците, следите, светлините и broadcast
 * звукът работят непроменени и в реплея/attract демото.
 *
 * Чете кадрите и трасето; не пипа симулацията.
 */

import { updateCarRig } from './car.js';
import { CAR, FIXED_DT } from './physics.js';
import { FRAME_EVERY, runoffRanges } from './sim.js';
import { bankAt, findKerbRanges, projectOnTrack } from './track.js';

/** Период между два записани кадъра (s): FRAME_EVERY тика по FIXED_DT. */
export const FRAME_DT = FRAME_EVERY * FIXED_DT;

/**
 * Подредба на записания кадър — ТОЧНО както sim.js го пише
 * (`recFrames.push(state.x, state.z, state.heading)`): x, z, heading.
 * Старият #updateGhost/#replayFrame в Game.js четеше [x, heading, z] и
 * слагаше духа на z ≈ ±π; всеки консуматор минава през sampleFrame().
 */
export const FRAME_STRIDE = 3;
const FRAME_X = 0;
const FRAME_Z = 1;
const FRAME_HEADING = 2;

/**
 * Скок между два последователни sample-а, над който хинтът се забравя
 * (телепорт при recovery в записа, сеек): най-бързият легитимен ход е
 * 92 m/s / 60 Hz ≈ 1.5 m, а хинтираният прозорец е ±40 m.
 */
const JUMP_RESET = 15;

/** Период на кербовата назъбеност (m) — общ договор (mesh, sim, audio, камера). */
const KERB_PERIOD = 0.9;

/** Изглаждане на производните (скорост/ускорение) — 1/s. */
const SPEED_SMOOTHING = 14;
const ACCEL_SMOOTHING = 8;

/** Отрицателно ускорение, под което реплей колата „спира" (m/s²). */
const BRAKE_DECEL = -8;

const TWO_PI = Math.PI * 2;

/**
 * @typedef {object} ReplayOut Състояние на реплей колата — едновременно
 *   „render" (x, z, heading, vForward…), „sim" (surface, trackIndexHint,
 *   onKerb, offSurface, lastProgress) и „out" (ax, ay) за камерата/ефектите.
 * @property {number} x
 * @property {number} y Височина на асфалта под колата (= surface.height)
 * @property {number} z
 * @property {number} heading
 * @property {number} vForward
 * @property {number} vLateral
 * @property {number} yawRate
 * @property {number} slip |vLateral| / max(|v|, 1) — както state.slip
 * @property {number} steer Реконструиран ъгъл на волана, −1..1
 * @property {number} throttle 0|1 (ускорява)
 * @property {number} brake 0|1 (забавя под BRAKE_DECEL)
 * @property {number} gLong Надлъжно ускорение (m/s²), изгладено
 * @property {number} ax = gLong (договорът на sim.state.out)
 * @property {number} ay Странично ускорение (yawRate·v)
 * @property {number} gradient
 * @property {number} bank
 * @property {number} height
 * @property {boolean} onKerb
 * @property {string|null} offSurface 'gravel'|'asphalt'|'grass'|null
 * @property {number} kerbSide Страната на керба, върху който е колата (−1/0/+1; 0 = не е на керб)
 * @property {number} trackIndexHint
 * @property {number} along Метри след индексната точка
 * @property {number} lateral Отместване от осевата (+ = дясно)
 * @property {number} distance Метри по обиколката
 * @property {number} lastProgress distance / дължина (0..1)
 * @property {{height: number, gradient: number, bank: number}} surface
 * @property {import('./car.js').CarDyn} dyn Визуалните сигнали за updateCarRig
 */

/**
 * @returns {ReplayOut}
 */
export function createReplayOut() {
    return {
        x: 0,
        y: 0,
        z: 0,
        heading: 0,
        vForward: 0,
        vLateral: 0,
        yawRate: 0,
        slip: 0,
        steer: 0,
        throttle: 0,
        brake: 0,
        gLong: 0,
        ax: 0,
        ay: 0,
        gradient: 0,
        bank: 0,
        height: 0,
        onKerb: false,
        offSurface: null,
        kerbSide: 0,
        trackIndexHint: 0,
        along: 0,
        lateral: 0,
        distance: 0,
        lastProgress: 0,
        surface: { height: 0, gradient: 0, bank: 0 },
        dyn: { gLong: 0, gLat: 0, brake: 0, throttle: 0, rumble: 0, kerbSide: 0 },
    };
}

/**
 * Позиция/посока в дробен кадър `t` (линейна интерполация, heading през
 * най-късата дъга). Общ вход за духа в живата обиколка и за реплея.
 *
 * @param {Float32Array} frames
 * @param {number} t 0 ≤ t ≤ брой кадри − 1 (клампва се)
 * @param {{x: number, z: number, heading: number}} out
 * @returns {{x: number, z: number, heading: number}} out
 */
export function sampleFrame(frames, t, out) {
    const n = Math.floor(frames.length / FRAME_STRIDE);
    const clampedT = t < 0 ? 0 : t > n - 1 - 1e-6 ? n - 1 - 1e-6 : t;
    const base = Math.floor(clampedT);
    const f = clampedT - base;
    const i0 = base * FRAME_STRIDE;
    const i1 = Math.min(i0 + FRAME_STRIDE, (n - 1) * FRAME_STRIDE);

    let dH = frames[i1 + FRAME_HEADING] - frames[i0 + FRAME_HEADING];
    if (dH > Math.PI) dH -= TWO_PI;
    else if (dH < -Math.PI) dH += TWO_PI;

    out.x = frames[i0 + FRAME_X] + (frames[i1 + FRAME_X] - frames[i0 + FRAME_X]) * f;
    out.z = frames[i0 + FRAME_Z] + (frames[i1 + FRAME_Z] - frames[i0 + FRAME_Z]) * f;
    out.heading = frames[i0 + FRAME_HEADING] + dH * f;

    return out;
}

/**
 * @typedef {object} ReplayDriver
 * @property {(frames: Float32Array, t: number, out: ReplayOut) => ReplayOut} sample
 *   Състоянието в дробен кадър t (0 ≤ t ≤ брой кадри − 1)
 * @property {(rig: import('./car.js').CarRig, out: ReplayOut, dt: number) => void} applyToRig
 *   Кара рига с реалната кинематика (позиция, наклони, колела, heave)
 * @property {(out: ReplayOut) => void} halt Спира колата на място (parc fermé): скорости/педали 0
 * @property {() => void} reset Забравя хинта и производните (сеек, нов запис)
 * @property {(frames: Float32Array) => number} frameCount
 */

/**
 * @param {import('./track.js').Track} track
 * @param {object} [circuit] За run-off вида (gravel/asphalt); липсва → трева
 * @returns {ReplayDriver}
 */
export function createReplayDriver(track, circuit = null) {
    const { count } = track;
    const runoffKind = circuit?.runoff ?? 'grass';

    // Същите таблици като в sim.js (кербове по ред, run-off битове по страна) —
    // локални копия: драйверът живее и без симулация (attract преди старта).
    const kerbSide = new Int8Array(count);
    for (const range of findKerbRanges(track)) {
        for (let r = range.from; r <= range.to; r++) {
            kerbSide[((r % count) + count) % count] = range.side;
        }
    }
    const runoffSide = new Uint8Array(count);
    if (runoffKind !== 'none' && runoffKind !== 'grass') {
        for (const range of runoffRanges(track)) {
            const bit = range.side < 0 ? 1 : 2;
            for (let r = range.from; r <= range.to; r++) {
                runoffSide[((r % count) + count) % count] |= bit;
            }
        }
    }

    let hint = null;
    let smoothV = null;
    let smoothVLat = 0;
    let smoothAccel = 0;
    let prevSpeed = 0;
    let prevT = null;
    let lastX = 0;
    let lastZ = 0;
    const projection = {};

    function reset() {
        hint = null;
        smoothV = null;
        smoothVLat = 0;
        smoothAccel = 0;
        prevSpeed = 0;
        prevT = null;
    }

    /**
     * @param {Float32Array} frames
     * @returns {number}
     */
    function frameCount(frames) {
        return Math.floor(frames.length / FRAME_STRIDE);
    }

    /**
     * @param {Float32Array} frames
     * @param {number} t
     * @param {ReplayOut} out
     * @returns {ReplayOut}
     */
    function sample(frames, t, out) {
        const n = frameCount(frames);
        const clampedT = t < 0 ? 0 : t > n - 1 - 1e-6 ? n - 1 - 1e-6 : t;
        const base = Math.floor(clampedT);
        const f = clampedT - base;
        const i0 = base * FRAME_STRIDE;
        const i1 = i0 + FRAME_STRIDE;

        const x0 = frames[i0 + FRAME_X];
        const z0 = frames[i0 + FRAME_Z];
        const h0 = frames[i0 + FRAME_HEADING];
        const x1 = frames[i1 + FRAME_X];
        const z1 = frames[i1 + FRAME_Z];
        const h1 = frames[i1 + FRAME_HEADING];

        let dH = h1 - h0;
        if (dH > Math.PI) dH -= TWO_PI;
        else if (dH < -Math.PI) dH += TWO_PI;

        const x = x0 + (x1 - x0) * f;
        const z = z0 + (z1 - z0) * f;
        const heading = h0 + dH * f;

        // Скорост от делтата на кадровата двойка (60 Hz) — в световни оси,
        // после в осите на болида: forward = (sin h, cos h), ляво = (cos h, −sin h)
        // (физиката интегрира +vLateral по лявата ос).
        // Телепорт вътре в записа (recovery на невалидна обиколка): двойката
        // кадри е скок, не движение — скоростта от нея е боклук (>90 m/s).
        const pairJump = Math.hypot(x1 - x0, z1 - z0) > JUMP_RESET;
        const vx = pairJump ? 0 : (x1 - x0) / FRAME_DT;
        const vz = pairJump ? 0 : (z1 - z0) / FRAME_DT;
        const sin = Math.sin(heading);
        const cos = Math.cos(heading);
        const rawForward = vx * sin + vz * cos;
        const rawLateral = vx * cos - vz * sin;
        const rawYaw = pairJump ? 0 : dH / FRAME_DT;

        // Времето между две извиквания — от самия реплей часовник (кадри), не
        // от rAF: сеек/скорост на възпроизвеждане не бива да „ускоряват" G-то.
        let elapsed = prevT === null ? FRAME_DT : (clampedT - prevT) * FRAME_DT;
        if (elapsed <= 0 || elapsed > 0.25) {
            // Wrap на обиколката или сеек: без производна през скока.
            elapsed = FRAME_DT;
            smoothV = null;
        }
        prevT = clampedT;

        if (smoothV === null) {
            smoothV = rawForward;
            smoothVLat = rawLateral;
            smoothAccel = 0;
            prevSpeed = Math.abs(rawForward);
        } else {
            const kv = 1 - Math.exp(-SPEED_SMOOTHING * elapsed);
            smoothV += (rawForward - smoothV) * kv;
            smoothVLat += (rawLateral - smoothVLat) * kv;
            const speedNow = Math.abs(smoothV);
            const rawAccel = (speedNow - prevSpeed) / elapsed;
            prevSpeed = speedNow;
            smoothAccel += (rawAccel - smoothAccel) * (1 - Math.exp(-ACCEL_SMOOTHING * elapsed));
        }

        const speed = Math.abs(smoothV);

        // ── Трасето под колата: хинтирана проекция (устойчива на стек хеърпини) ──
        // Телепорт в записа (recovery) → глобален скан, иначе хинтът остава
        // на стария клон и колата „потъва" за секунди.
        if (hint !== null && Math.hypot(x - lastX, z - lastZ) > JUMP_RESET) {
            hint = null;
            smoothV = null;
        }
        lastX = x;
        lastZ = z;
        projectOnTrack(track, x, z, hint, projection);
        hint = projection.index;
        const index = projection.index;
        const bank = bankAt(track, index, projection.along);
        const height = projection.height - projection.lateral * bank;

        const half = track.halfWidths[index];
        const absLateral = Math.abs(projection.lateral);
        const lateralSign = projection.lateral > 0 ? 1 : -1;
        const kerbHere = kerbSide[index] === lateralSign;
        const onTrack = absLateral < half || (kerbHere && absLateral < half + 1.15);
        const onKerb = onTrack && kerbHere && absLateral > half - 0.45;

        let offSurface = null;
        if (!onTrack) {
            const zoneBit = lateralSign > 0 ? 1 : 2;
            offSurface =
                (runoffSide[index] & zoneBit) !== 0 && absLateral < half + 8 ? runoffKind : 'grass';
        }

        const ay = clamp(rawYaw * smoothV, -40, 40);

        out.x = x;
        out.y = height;
        out.z = z;
        out.heading = heading;
        out.vForward = smoothV;
        out.vLateral = smoothVLat;
        out.yawRate = rawYaw;
        out.slip = Math.min(1, Math.abs(smoothVLat) / Math.max(speed, 1));
        // Воланът от кинематиката: δ ≈ atan(yawRate·L / v), нормиран към упора.
        out.steer =
            speed > 2
                ? clamp(Math.atan((rawYaw * CAR.wheelbase) / speed) / CAR.maxSteerAngle, -1, 1)
                : 0;
        out.gLong = smoothAccel;
        out.ax = smoothAccel;
        out.ay = ay;
        out.brake = smoothAccel < BRAKE_DECEL ? 1 : 0;
        out.throttle = smoothAccel > 0.5 && speed > 1 ? 1 : 0;
        out.gradient = projection.gradient;
        out.bank = bank;
        out.height = height;
        out.onKerb = onKerb;
        out.offSurface = offSurface;
        out.kerbSide = onKerb ? lateralSign : 0;
        out.trackIndexHint = index;
        out.along = projection.along;
        out.lateral = projection.lateral;
        out.distance = projection.distance;
        out.lastProgress = Math.min(1, Math.max(0, projection.distance / track.length));
        out.surface.height = height;
        out.surface.gradient = projection.gradient;
        out.surface.bank = bank;

        // Кербовият хийв по ПРОГРЕСА с периода на назъбеността — същата фаза
        // като кика в sim, дрънченето в audio и трептенето на камерата.
        const dyn = out.dyn;
        dyn.gLong = smoothAccel;
        dyn.gLat = ay;
        dyn.brake = out.brake;
        dyn.throttle = out.throttle;
        dyn.rumble =
            onKerb && speed > 8
                ? Math.sin((projection.distance * TWO_PI) / KERB_PERIOD) * 0.012 * Math.min(1, speed / 45)
                : 0;
        dyn.kerbSide = out.kerbSide;

        return out;
    }

    /**
     * Ригът повтаря кадъра с реалните числа: колелата се въртят със скоростта
     * от кадрите, носът следва склона, тялото ляга по банкинга и G-тата.
     *
     * @param {import('./car.js').CarRig} rig
     * @param {ReplayOut} out
     * @param {number} dt
     */
    function applyToRig(rig, out, dt) {
        updateCarRig(rig, out, out.surface, dt, out.dyn);
    }

    /**
     * Parc fermé: колата стои. Позицията/трасето остават от последния sample.
     *
     * @param {ReplayOut} out
     */
    function halt(out) {
        out.vForward = 0;
        out.vLateral = 0;
        out.yawRate = 0;
        out.slip = 0;
        out.steer = 0;
        out.throttle = 0;
        out.brake = 0;
        out.gLong = 0;
        out.ax = 0;
        out.ay = 0;
        out.onKerb = false;
        out.kerbSide = 0;
        const dyn = out.dyn;
        dyn.gLong = 0;
        dyn.gLat = 0;
        dyn.brake = 0;
        dyn.throttle = 0;
        dyn.rumble = 0;
        dyn.kerbSide = 0;
        smoothV = null;
        prevT = null;
    }

    return { sample, applyToRig, halt, reset, frameCount };
}

/**
 * Хинтирана сонда за височината на асфалта под точка (духът в живата
 * обиколка): projectOnTrack с постоянен хинт вместо небрежен скан на всяка
 * 4-та точка без прозорец — по-евтино (~20 итерации) и не прескача на
 * другия клон при стек хеърпин (потъващ/летящ дух за няколко кадъра).
 *
 * @param {import('./track.js').Track} track
 * @returns {{height: (x: number, z: number) => number, reset: () => void}}
 */
export function createTrackHeightProbe(track) {
    let hint = null;
    let lastX = 0;
    let lastZ = 0;
    const projection = {};

    return {
        height(x, z) {
            if (hint !== null && Math.hypot(x - lastX, z - lastZ) > JUMP_RESET) {
                hint = null;
            }
            lastX = x;
            lastZ = z;
            projectOnTrack(track, x, z, hint, projection);
            hint = projection.index;
            return projection.height - projection.lateral * bankAt(track, projection.index, projection.along);
        },
        reset() {
            hint = null;
        },
    };
}

/**
 * @param {number} v
 * @param {number} min
 * @param {number} max
 * @returns {number}
 */
function clamp(v, min, max) {
    return v < min ? min : v > max ? max : v;
}
