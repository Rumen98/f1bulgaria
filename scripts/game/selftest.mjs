/**
 * Селфтест на детерминизма: прост автопилот кара обиколка в симулацията,
 * записът ѝ се преиграва и времената трябва да съвпаднат ТОЧНО (същият
 * двигател → бит-идентично). Пада с код 1 при разминаване.
 *
 *   node scripts/game/selftest.mjs [public/game-tracks/monza.json]
 *
 * Автопилотът е умишлено посредствен — излита, връща се (recovery),
 * събира предупреждения: точно грозните пътища на симулацията, които
 * валидацията трябва да възпроизвежда 1:1.
 */

import { readFileSync } from 'node:fs';

import { FIXED_DT } from '../../resources/js/game/physics.js';
import { createSimFromData, decodeTrace, encodeTrace, readTraceInput } from '../../resources/js/game/sim.js';

const trackFile = process.argv[2] ?? 'public/game-tracks/monza.json';
const trackData = JSON.parse(readFileSync(trackFile, 'utf8'));

/**
 * Прост pursuit контролер: целим точка напред по осевата линия, P-режим на
 * ъгъла, газ според кривината напред. Достатъчен да завърши обиколка с
 * помощта на мрежата за връщане.
 */
function autopilot(sim, input) {
    const t = sim.track;
    const state = sim.state;
    const hint = sim.trackIndexHint ?? 0;

    const ahead = (m) => ((hint + Math.round(m / t.spacing)) % t.count + t.count) % t.count;

    // Lookahead по скоростта: фибата на Монако не се взима с далечна цел.
    const target = ahead(10 + Math.max(0, state.vForward) * 0.4);
    const dx = t.xs[target] - state.x;
    const dz = t.zs[target] - state.z;
    const desired = Math.atan2(dx, dz);

    let dH = desired - state.heading;
    while (dH > Math.PI) dH -= 2 * Math.PI;
    while (dH < -Math.PI) dH += 2 * Math.PI;

    // Волан: физическият знак е обратен на екранния (виж Game.#readInput).
    input.steer = Math.max(-1, Math.min(1, dH * 2.8));

    // Газ/спирачка според най-острото в следващите 90 метра — консервативно:
    // ботът няма фина спирачка, по-добре бавен, отколкото в жив-цикъл на
    // фибата на Монако.
    let peak = 0;
    for (let m = 0; m <= 90; m += t.spacing) {
        peak = Math.max(peak, Math.abs(t.curvature[ahead(m)]));
    }
    // Освен сцеплението има и ГЕОМЕТРИЧЕН таван: ъгълът на волана пада със
    // скоростта (steerSpeedFalloff), а фибата на Монако иска пълен ъгъл —
    // достижим само под ~4-5 m/s. Решаваме за v от радиуса на завоя.
    const needAngle = Math.atan(3.6 * Math.max(peak, 1e-4) * 1.2);
    const vGeo = Math.max(4, (0.58 / needAngle - 1) / 0.075);
    const safeSpeed = Math.max(4.5, Math.min(70, Math.min(Math.sqrt(15 / Math.max(peak, 1e-4)), vGeo)));
    input.throttle = state.vForward < safeSpeed ? 1 : 0;
    input.brake = state.vForward > safeSpeed + 3 ? 1 : 0;

    return input;
}

// ── Караме до първата завършена летяща обиколка ──────────────────────────
const sim = createSimFromData(trackData);
const input = { steer: 0, throttle: 0, brake: 0 };
const maxTicks = 10 * 60 * 120; // 10 минути симулация — таван

let finished = null;
let ticks = 0;
for (; ticks < maxTicks; ticks++) {
    autopilot(sim, input);
    const event = sim.tick(input);
    if (event?.type === 'finished') {
        finished = event;
        break;
    }
}

if (!finished || !finished.trace) {
    console.error(`СЕЛФТЕСТ: автопилотът не завърши обиколка за ${ticks} тика`);
    process.exit(1);
}

console.log(
    `Обиколка: ${finished.lapMs} ms (${(ticks * FIXED_DT).toFixed(1)} s сим време), ` +
    `трейс ${finished.trace.inputs.length / 2} тика, кадри ${finished.frames ? finished.frames.length / 3 : 0}`
);

// ── Преиграване през сериализация (същият път като сървъра) ──────────────
const trace = decodeTrace(encodeTrace(finished.trace));
const replaySim = createSimFromData(trackData);
replaySim.restoreForReplay(trace.start);

const replayInput = { steer: 0, throttle: 0, brake: 0 };
const traceTicks = Math.floor(trace.inputs.length / 2);
let replayed = null;

for (let i = 0; i < traceTicks; i++) {
    readTraceInput(trace.inputs, i, replayInput);
    const event = replaySim.tick(replayInput);
    if (event?.type === 'finished') {
        replayed = event;
        break;
    }
}

if (!replayed) {
    console.error('СЕЛФТЕСТ: преиграването не финишира');
    process.exit(1);
}

if (replayed.lapMs !== finished.lapMs) {
    console.error(`СЕЛФТЕСТ: времената се разминават — играно ${finished.lapMs} ms, преиграно ${replayed.lapMs} ms`);
    process.exit(1);
}

for (let i = 0; i < 3; i++) {
    if (replayed.sectorsMs[i] !== finished.sectorsMs[i]) {
        console.error(`СЕЛФТЕСТ: сектор ${i + 1} се разминава — ${finished.sectorsMs[i]} срещу ${replayed.sectorsMs[i]}`);
        process.exit(1);
    }
}

console.log(`СЕЛФТЕСТ ОК: преиграването възпроизведе ${replayed.lapMs} ms точно.`);
