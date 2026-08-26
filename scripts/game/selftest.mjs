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

import { driveAutopilot } from '../../resources/js/game/autopilot.js';
import { FIXED_DT } from '../../resources/js/game/physics.js';
import { createSimFromData, decodeTrace, encodeTrace, readTraceInput } from '../../resources/js/game/sim.js';

const trackFile = process.argv[2] ?? 'public/game-tracks/monza.json';
const trackData = JSON.parse(readFileSync(trackFile, 'utf8'));

// ── Караме до първата завършена летяща обиколка ──────────────────────────
const sim = createSimFromData(trackData);
const input = { steer: 0, throttle: 0, brake: 0 };
const maxTicks = 10 * 60 * 120; // 10 минути симулация — таван

let finished = null;
let ticks = 0;
for (; ticks < maxTicks; ticks++) {
    driveAutopilot(sim, input);
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
