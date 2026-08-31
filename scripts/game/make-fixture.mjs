/**
 * Генерира тестовата фикстура за e2e валидацията (Pest →
 * tests/Fixtures/game/monza-lap.json): автопилотът кара една честна
 * обиколка и записът ѝ се дъмпва заедно с времената.
 *
 *   node scripts/game/make-fixture.mjs
 *
 * Пуска се след ВСЯКА промяна в физиката/симулацията (SIM_VERSION bump) —
 * иначе e2e тестът ще реже фикстурата като version_mismatch.
 */

import { readFileSync, writeFileSync } from 'node:fs';

import { driveAutopilot } from '../../resources/js/game/autopilot.js';
import { SIM_VERSION, createSimFromData, encodeTrace } from '../../resources/js/game/sim.js';

const trackFile = 'public/game-tracks/monza.json';
const sim = createSimFromData(JSON.parse(readFileSync(trackFile, 'utf8')));

const input = { steer: 0, throttle: 0, brake: 0 };
let finished = null;

for (let tick = 0; tick < 10 * 60 * 120 && !finished; tick++) {
    driveAutopilot(sim, input);
    const event = sim.tick(input);
    if (event?.type === 'finished') {
        finished = event;
    }
}

if (!finished || !finished.trace || !finished.valid) {
    console.error('Автопилотът не завърши валидна обиколка — фикстурата не е обновена.');
    process.exit(1);
}

const fixture = {
    lap_ms: finished.lapMs,
    sectors_ms: finished.sectorsMs,
    sim_version: SIM_VERSION,
    trace: encodeTrace(finished.trace),
};

writeFileSync('tests/Fixtures/game/monza-lap.json', JSON.stringify(fixture));
console.log(
    `Фикстура обновена: ${finished.lapMs} ms, сектори ${finished.sectorsMs.join('/')}, sim v${SIM_VERSION}.`
);
