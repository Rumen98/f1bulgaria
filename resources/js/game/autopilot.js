/**
 * Автопилот за симулацията: прост pursuit контролер — цели точка напред по
 * осевата линия, P-режим на ъгъла, газ според кривината напред.
 *
 * Ползва се на две места:
 *  - scripts/game/selftest.mjs — детерминизъм тест (параметри по подразбиране;
 *    НЕ ги променяй, без да провериш селфтеста — той пази известното време);
 *  - Game.js — AI съперниците в „състезание": всяка кола със собствен темп
 *    (pace), агресия на волана (steerGain) и офсет на целта (lookBias), за да
 *    не карат в индийска нишка по една и съща линия.
 *
 * Чист модул без three.js/DOM — върви и в Node, и в браузъра.
 */

/**
 * Смята входа за един тик. Мутира `input` и го връща.
 *
 * @param {import('./sim.js').Simulation} sim
 * @param {{steer: number, throttle: number, brake: number}} input
 * @param {{pace?: number, steerGain?: number, lookBias?: number,
 *          lineOffset?: number, others?: Array<import('./sim.js').Simulation>}} [opts]
 *        pace множи позволената скорост (1 = еталонният автопилот),
 *        steerGain е P-коефициентът на волана, lookBias мести целта в метри,
 *        lineOffset е страничен офсет на линията в метри (всяка кола своя),
 *        others са СИМУЛАЦИИТЕ на останалите коли — за избягване (стабилни
 *        обекти; кола в „Връщане на пистата" е недосегаема и се игнорира).
 * @returns {{steer: number, throttle: number, brake: number}}
 */
export function driveAutopilot(sim, input, opts = {}) {
    const pace = opts.pace ?? 1;
    const steerGain = opts.steerGain ?? 2.8;
    const lookBias = opts.lookBias ?? 0;

    const t = sim.track;
    const state = sim.state;
    const hint = sim.trackIndexHint ?? 0;

    const ahead = (m) => ((hint + Math.round(m / t.spacing)) % t.count + t.count) % t.count;

    // ── Трафик: НАЙ-БЛИЗКАТА кола напред в нашата лента → мини от другата ѝ
    // страна; близо и по-бавна → лифт; съвсем близо и много по-бавна → спри.
    let avoidShift = 0;
    let liftForTraffic = false;
    let brakeForTraffic = false;

    if (opts.others) {
        const fx = Math.sin(state.heading);
        const fz = Math.cos(state.heading);
        let nearest = Infinity;

        for (const otherSim of opts.others) {
            // Възстановяваща се кола е недосегаема (извадена от контактите).
            if (otherSim.recovering) {
                continue;
            }

            const other = otherSim.state;
            const dx = other.x - state.x;
            const dz = other.z - state.z;
            const alongD = dx * fx + dz * fz;

            if (alongD < 2 || alongD > 30 || alongD >= nearest) {
                continue;
            }

            const sideD = dx * fz - dz * fx;
            if (Math.abs(sideD) > 3) {
                continue;
            }

            nearest = alongD;
            // Локалната странична ос (cos h, -sin h) сочи СРЕЩУ нормалата на
            // трасето (виж physics.js) → съсед на +sideD стои откъм −n и се
            // подминава с ПОЛОЖИТЕЛЕН офсет по нормалата, и обратно.
            avoidShift = sideD > 0 ? 2.2 : -2.2;
            liftForTraffic = alongD < 9 && other.vForward < state.vForward - 2;
            brakeForTraffic = alongD < 8 && other.vForward < state.vForward - 6;
        }
    }

    // Lookahead по скоростта: фибата на Монако не се взима с далечна цел.
    const target = ahead(10 + lookBias + Math.max(0, state.vForward) * 0.4);

    // Собствената състезателна линия + отклонението за трафика — целта се
    // мести странично по нормалата, клампната по ШИРИНАТА на пистата там
    // (на тясното Монако широка линия = стена/невалидност).
    const maxOffset = Math.max(0.6, t.halfWidths[target] - 2.9);
    const offset = clamp((opts.lineOffset ?? 0) + avoidShift, -maxOffset, maxOffset);
    const dx = t.xs[target] + t.nx[target] * offset - state.x;
    const dz = t.zs[target] + t.nz[target] * offset - state.z;
    const desired = Math.atan2(dx, dz);

    let dH = desired - state.heading;
    while (dH > Math.PI) dH -= 2 * Math.PI;
    while (dH < -Math.PI) dH += 2 * Math.PI;

    // Волан: физическият знак е обратен на екранния (виж Game.#readInput).
    input.steer = Math.max(-1, Math.min(1, dH * steerGain));

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
    const safeSpeed =
        Math.max(4.5, Math.min(70, Math.min(Math.sqrt(15 / Math.max(peak, 1e-4)), vGeo))) * pace;
    input.throttle = state.vForward < safeSpeed && !liftForTraffic && !brakeForTraffic ? 1 : 0;
    input.brake = state.vForward > safeSpeed + 3 || brakeForTraffic ? 1 : 0;

    return input;
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
