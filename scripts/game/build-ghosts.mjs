/**
 * Генерира официалните духове на Падок: за всяка писта автопилотът кара
 * еталонна обиколка по състезателната линия и кадрите ѝ се записват в
 * public/game-ghosts/{slug}.json. Играта ги показва (златисти) на играчи
 * без собствен рекорд — и първият на пистата има срещу кого да кара.
 *
 *   node scripts/game/build-ghosts.mjs
 *
 * Пуска се след промяна на физиката/линията (заедно с make-fixture.mjs) —
 * духовете носят SIM_VERSION и стари версии тихо отпадат в клиента.
 */

import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';

import { driveAutopilot } from '../../resources/js/game/autopilot.js';
import { SIM_VERSION, createSimFromData, encodeFrames } from '../../resources/js/game/sim.js';

const tracks = JSON.parse(readFileSync('public/game-tracks/index.json', 'utf8'));
mkdirSync('public/game-ghosts', { recursive: true });

let failed = false;

for (const meta of tracks) {
    const trackData = JSON.parse(readFileSync(`public/game-tracks/${meta.slug}.json`, 'utf8'));

    // Валидна обиколка или нищо: при излизане се пробва по-кротко темпо.
    let ghost = null;
    for (const pace of [1, 0.96, 0.92, 0.88, 0.84]) {
        const sim = createSimFromData(trackData);
        const input = { steer: 0, throttle: 0, brake: 0 };
        let finished = null;

        for (let tick = 0; tick < 10 * 60 * 120 && !finished; tick++) {
            driveAutopilot(sim, input, { pace });
            const event = sim.tick(input);
            if (event?.type === 'finished') {
                finished = event;
            }
        }

        if (finished?.valid && finished.frames) {
            ghost = { pace, finished };
            break;
        }
    }

    if (!ghost) {
        console.error(`${meta.slug}: автопилотът не завърши валидна обиколка — духът е пропуснат.`);
        failed = true;
        continue;
    }

    writeFileSync(
        `public/game-ghosts/${meta.slug}.json`,
        JSON.stringify({
            v: SIM_VERSION,
            lapMs: ghost.finished.lapMs,
            lapTicks: ghost.finished.lapTicks,
            frames: encodeFrames(ghost.finished.frames),
        })
    );
    console.log(
        `${meta.slug}: ${(ghost.finished.lapMs / 1000).toFixed(3)} s` +
        (ghost.pace < 1 ? ` (pace ${ghost.pace})` : '')
    );
}

process.exit(failed ? 1 : 0);
