/**
 * Сървърна валидация на обиколка от Хронометъра: преиграва записания вход
 * през СЪЩАТА симулация (resources/js/game/sim.js), която е играл клиентът,
 * и печата резултата като JSON на stdout.
 *
 * Вика се от ValidateGameLapJob с път до payload файл:
 *   node scripts/game/validate-lap.mjs /tmp/payload.json
 *
 * payload: { "trackFile": "/path/to/monza.json", "trace": "<encodeTrace JSON>" }
 * изход:   { "status": "finished|incomplete|version_mismatch|bad_trace",
 *            "lapMs": 84123|null, "sectorsMs": [..]|null, "valid": true|false }
 *
 * Детерминизъм: V8 → V8 (Chrome/Edge клиенти) е бит-идентичен; други
 * двигатели (Firefox/Safari) може да се разминат с милисекунди заради
 * Math.sin/cos — затова PHP страната сравнява с толеранс, не точно.
 */

import { readFileSync } from 'node:fs';

import { SIM_VERSION, createSimFromData, decodeTrace, encodeFrames, readTraceInput } from '../../resources/js/game/sim.js';
import { projectOnTrack } from '../../resources/js/game/track.js';

const payloadPath = process.argv[2];

if (!payloadPath) {
    console.log(JSON.stringify({ status: 'bad_trace', lapMs: null, sectorsMs: null, valid: false }));
    process.exit(0);
}

const fail = (status) => {
    console.log(JSON.stringify({ status, lapMs: null, sectorsMs: null, valid: false }));
    process.exit(0);
};

let payload;
let trackData;
try {
    payload = JSON.parse(readFileSync(payloadPath, 'utf8'));
    trackData = JSON.parse(readFileSync(payload.trackFile, 'utf8'));
} catch {
    fail('bad_trace');
}

const trace = decodeTrace(payload.trace);

if (!trace || !trace.start || !(trace.inputs?.length > 0)) {
    fail('bad_trace');
}

if (trace.v !== SIM_VERSION) {
    // Стар клиент срещу нова физика — не може да се повтори честно.
    fail('version_mismatch');
}

const sim = createSimFromData(trackData);
// Ако преиграваният трейс съдържа непълна обиколка, re-arm-ът би пуснал
// запис НА сървъра — безвреден за физиката, но безсмислена памет.
sim.recordEnabled = false;

// Санитарни проверки на стартовия снапшот — клиентът го контролира изцяло.
// Честният запис тръгва ТОЧНО от прекосяването на стартовата линия (там
// се въоръжава хронометърът); изкован старт на 2/3 от обиколката би минал
// „пълна обиколка" с 1/3 каране.
{
    const s = trace.start;
    const numbers = [s.x, s.z, s.heading, s.vForward, s.vLateral, s.steer, s.yawRate, s.slip, s.lastProgress];

    if (!numbers.every((v) => Number.isFinite(v)) || !Number.isInteger(s.hint) || !Number.isInteger(s.sector)) {
        fail('bad_trace');
    }
    // Track-limits снапшотът (v2): броячите пътуват с трейса, за да е
    // lapValid идентичен на живото — но клиентът ги контролира, затова
    // граници. cut > CUT_COOLDOWN или отрицателен offTrack са фалшификат.
    if (
        !Number.isInteger(s.offTrack) || s.offTrack < 0 || s.offTrack > 200000 ||
        !Number.isInteger(s.cut) || s.cut < 0 || s.cut > 600
    ) {
        fail('bad_trace');
    }
    if (Math.abs(s.vForward) > 97 || Math.abs(s.vLateral) > 105 || Math.abs(s.steer) > 1) {
        fail('bad_trace');
    }

    const projection = projectOnTrack(sim.track, s.x, s.z, null, {});
    const progress = projection.distance / sim.track.length;
    // Честното въоръжаване става на ТИКА на пресичане: най-много ~0.8 м след
    // линията (една стъпка при пълна скорост). Старият праг 0.15 подаряваше
    // цели 15% от обиколката на кован старт.
    const nearLine = progress > 0.995 || progress < 0.01;
    const onSurface = Math.abs(projection.lateral) < sim.track.halfWidths[projection.index] + 2.5;

    if (!nearLine || !onSurface) {
        fail('bad_trace');
    }
}

sim.restoreForReplay(trace.start);

// Преиграването „снима" обиколката: кадрите стават сървърният дух на
// потребителя (дуелите „Карай срещу…" в класацията). Входовете се записват
// повторно покрай тях — шепа килобайти, изхвърлят се.
sim.recording = true;

const input = { steer: 0, throttle: 0, brake: 0 };
const ticks = Math.floor(trace.inputs.length / 2);
let finished = null;

for (let i = 0; i < ticks; i++) {
    readTraceInput(trace.inputs, i, input);
    const event = sim.tick(input);

    if (event?.type === 'finished') {
        finished = event;
        break;
    }
}

console.log(
    JSON.stringify(
        finished
            ? {
                status: 'finished',
                lapMs: finished.lapMs,
                sectorsMs: finished.sectorsMs,
                valid: finished.valid,
                lapTicks: finished.lapTicks,
                // Кадрите на духа — само при валидна обиколка (невалидната
                // не влиза в класацията, няма срещу кого да се кара).
                frames: finished.valid && finished.frames ? encodeFrames(finished.frames) : null,
            }
            : { status: 'incomplete', lapMs: null, sectorsMs: null, valid: false }
    )
);
