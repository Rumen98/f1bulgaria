/**
 * Deterministic pursuit controller used by the Node self-test and AI opponents.
 *
 * The public API deliberately stays small. Racecraft state is kept per Simulation
 * in a WeakMap, so callers may keep passing short-lived option objects (the ghost
 * builder does this) without losing an overtake or defence halfway through it.
 */

const EMPTY_OPTIONS = Object.freeze({});
const TRACK_PLANS = new WeakMap();
const DRIVER_STATES = new WeakMap();

const TWO_PI = Math.PI * 2;
const PASS_OFFSET = 2.55;
const DEFEND_OFFSET = 1.35;
const AVOID_OFFSET = 3.05;
const PASS_MAX_TICKS = 7 * 120;
const DEFEND_MAX_TICKS = 3 * 120;
const MANEUVER_RATE = 3.8 / 120;
const RETURN_RATE = 2.0 / 120;
const BRAKE_DECEL = 19;
const TRAFFIC_DECEL = 18;

/**
 * Calculate one fixed-tick input. Mutates and returns `input`.
 *
 * @param {import('./sim.js').Simulation} sim
 * @param {{steer: number, throttle: number, brake: number}} input
 * @param {{pace?: number, steerGain?: number, lookBias?: number,
 *          lineOffset?: number, others?: Array<import('./sim.js').Simulation>}} [opts]
 * @returns {{steer: number, throttle: number, brake: number}}
 */
export function driveAutopilot(sim, input, opts = EMPTY_OPTIONS) {
    const pace = opts.pace ?? 1;
    const steerGain = opts.steerGain ?? 2.8;
    const lookBias = opts.lookBias ?? 0;
    const personalOffset = opts.lineOffset ?? 0;
    const others = opts.others;

    const track = sim.track;
    const state = sim.state;
    const hint = normalizeIndex(sim.trackIndexHint ?? 0, track.count);
    const driver = driverStateFor(sim);
    const plan = trackPlanFor(track);

    refreshDriverState(driver, sim);
    if (driver.passCooldown > 0) driver.passCooldown--;
    if (driver.defendCooldown > 0) driver.defendCooldown--;

    const ownProgress = progressOf(sim, hint);
    const ownLateral = lateralPosition(sim, hint);
    const ownSpeed = Math.max(0, state.vForward);

    let frontSim = null;
    let frontGap = Infinity;
    let frontLateral = 0;
    let frontSpeed = 0;

    let rearSim = null;
    let rearGap = -Infinity;
    let rearLateral = 0;
    let rearSpeed = 0;

    let sideSim = null;
    let sideGap = Infinity;
    let sideLateral = 0;

    let passSeen = false;
    let passGap = Infinity;
    let passLateral = 0;
    let defendSeen = false;
    let defendGap = -Infinity;

    if (others) {
        for (let i = 0; i < others.length; i++) {
            const otherSim = others[i];
            if (!otherSim || otherSim === sim || otherSim.recovering) continue;

            const otherHint = normalizeIndex(otherSim.trackIndexHint ?? 0, track.count);
            const gap = relativeGap(
                ownProgress,
                progressOf(otherSim, otherHint),
                track.length
            );
            const otherLateral = lateralPosition(otherSim, otherHint);
            const lateralGap = otherLateral - ownLateral;
            const otherSpeed = Math.max(0, otherSim.state.vForward);

            if (otherSim === driver.passTarget) {
                passSeen = true;
                passGap = gap;
                passLateral = lateralGap;
            }
            if (otherSim === driver.defendTarget) {
                defendSeen = true;
                defendGap = gap;
            }

            // Cars on another section of a crossing track are excluded by progress,
            // instead of relying on world-space distance alone.
            if (
                gap > 0.35 &&
                gap < 46 &&
                Math.abs(lateralGap) < 3.65 &&
                gap < frontGap
            ) {
                frontSim = otherSim;
                frontGap = gap;
                frontLateral = otherLateral;
                frontSpeed = otherSpeed;
            }

            if (
                gap < -0.35 &&
                gap > -28 &&
                Math.abs(lateralGap) < 4.2 &&
                gap > rearGap
            ) {
                rearSim = otherSim;
                rearGap = gap;
                rearLateral = otherLateral;
                rearSpeed = otherSpeed;
            }

            if (
                Math.abs(gap) < 4.8 &&
                Math.abs(lateralGap) < 2.45 &&
                Math.abs(gap) < Math.abs(sideGap)
            ) {
                sideSim = otherSim;
                sideGap = gap;
                sideLateral = lateralGap;
            }
        }
    }

    updatePassState(
        driver,
        sim,
        opts,
        frontSim,
        frontGap,
        frontLateral,
        frontSpeed,
        passSeen,
        passGap,
        ownLateral,
        ownSpeed,
        hint
    );
    updateDefenceState(
        driver,
        sim,
        opts,
        rearSim,
        rearGap,
        rearLateral,
        rearSpeed,
        defendSeen,
        defendGap,
        ownLateral,
        ownSpeed,
        hint
    );

    let maneuverTarget = 0;
    if (driver.passTarget) {
        driver.passTicks++;
        maneuverTarget = driver.passSide * PASS_OFFSET;
    } else if (driver.defendTarget && defendGap < -4.5) {
        driver.defendTicks++;
        maneuverTarget = driver.defendSide * DEFEND_OFFSET;
    }

    let emergencyTrafficBrake = false;
    if (sideSim) {
        let escapeSide = sideLateral > 0 ? -1 : 1;
        if (Math.abs(sideLateral) < 0.05) {
            escapeSide = driver.passSide || driverBias(opts);
        }

        const base = track.raceOffset[hint] + personalOffset;
        if (laneFits(track, hint, base + escapeSide * AVOID_OFFSET, 0.15)) {
            maneuverTarget = escapeSide * AVOID_OFFSET;
        } else {
            emergencyTrafficBrake = sideGap > -2.8;
        }
    }

    if (sim.recovering || sim.offSurface) {
        maneuverTarget = 0;
    }

    const maneuverDelta = maneuverTarget - driver.maneuverOffset;
    const maneuverRate = maneuverTarget === 0 ? RETURN_RATE : MANEUVER_RATE;
    driver.maneuverOffset += clamp(maneuverDelta, -maneuverRate, maneuverRate);

    const hereCurvature = Math.abs(track.raceCurv[hint]);
    const lookScale = 1 / (1 + hereCurvature * 6);
    const targetDistance =
        (10 + lookBias + ownSpeed * 0.4) * lookScale;
    const target = indexAhead(track, hint, Math.max(track.spacing, targetDistance));

    const maxOffset = laneLimit(track, target);
    const offset = clamp(
        track.raceOffset[target] + personalOffset + driver.maneuverOffset,
        -maxOffset,
        maxOffset
    );
    const dx = track.xs[target] + track.nx[target] * offset - state.x;
    const dz = track.zs[target] + track.nz[target] * offset - state.z;
    const desiredHeading = Math.atan2(dx, dz);
    const headingError = wrapAngle(desiredHeading - state.heading);

    // A small yaw-rate term damps weave when returning from a completed move.
    input.steer = clamp(headingError * steerGain - state.yawRate * 0.035, -1, 1);

    const linePenalty = 1 - Math.min(0.11, Math.abs(driver.maneuverOffset) * 0.035);
    let safeSpeed = brakingEnvelopeSpeed(
        track,
        plan,
        hint,
        ownSpeed,
        pace,
        linePenalty
    );

    // Keep a real stopping envelope behind a car until there is enough actual
    // lateral separation to call the overtake established.
    if (frontSim && Math.abs(frontLateral - ownLateral) < 2.3) {
        const standOff = 5.3 + Math.max(0, ownSpeed - frontSpeed) * 0.08;
        const usableGap = Math.max(0, frontGap - standOff);
        const followSpeed = Math.sqrt(
            Math.max(0, frontSpeed * frontSpeed + 2 * TRAFFIC_DECEL * usableGap)
        );
        safeSpeed = Math.min(safeSpeed, followSpeed);

        if (
            frontGap < 5.5 ||
            (frontGap < 9 && ownSpeed > frontSpeed + 2.5)
        ) {
            emergencyTrafficBrake = true;
        }
    }

    if (sim.offSurface) {
        safeSpeed = Math.min(safeSpeed, 18);
    }

    const overspeed = ownSpeed - safeSpeed;
    const brakeMargin = Math.max(1.8, safeSpeed * 0.045);
    input.brake = overspeed > brakeMargin || emergencyTrafficBrake ? 1 : 0;
    input.throttle =
        !input.brake && ownSpeed < safeSpeed - 0.8 && !sim.recovering ? 1 : 0;

    driver.lastTick = Number.isFinite(sim._simTick) ? sim._simTick : driver.lastTick + 1;
    driver.lastX = state.x;
    driver.lastZ = state.z;

    return input;
}

/**
 * Start, hold and finish a pass. The chosen side is latched, which prevents the
 * old left/right oscillation when two lane scores are almost equal.
 */
function updatePassState(
    driver,
    sim,
    opts,
    frontSim,
    frontGap,
    frontLateral,
    frontSpeed,
    passSeen,
    passGap,
    ownLateral,
    ownSpeed,
    hint
) {
    if (driver.passTarget) {
        if (
            !passSeen ||
            driver.passTarget.recovering ||
            passGap < -9 ||
            passGap > 55 ||
            driver.passTicks >= PASS_MAX_TICKS ||
            sim.offSurface
        ) {
            clearPass(driver, 180);
        }
        return;
    }

    if (
        !frontSim ||
        driver.passCooldown > 0 ||
        driver.defendTarget ||
        sim.offSurface ||
        sim.recovering
    ) {
        return;
    }

    const closingSpeed = ownSpeed - frontSpeed;
    if (closingSpeed <= 0.8 || ownSpeed < 7) return;

    const catchDistance =
        (ownSpeed * ownSpeed - frontSpeed * frontSpeed) / (2 * TRAFFIC_DECEL) + 7;
    const triggerDistance = clamp(catchDistance, 13, 39);
    if (frontGap > triggerDistance) return;

    // Do not initiate a lane change at the apex. An already active move is held.
    if (Math.abs(sim.track.raceCurv[hint]) > 0.045) return;

    const side = choosePassSide(
        sim,
        opts,
        frontSim,
        frontLateral,
        ownLateral,
        hint
    );
    if (side === 0) return;

    driver.passTarget = frontSim;
    driver.passSide = side;
    driver.passTicks = 0;
    clearDefence(driver, 120);
}

/**
 * Make one restrained defensive move when a faster car will arrive shortly.
 * Defence stops before the cars overlap, leaving side-by-side avoidance in charge.
 */
function updateDefenceState(
    driver,
    sim,
    opts,
    rearSim,
    rearGap,
    rearLateral,
    rearSpeed,
    defendSeen,
    defendGap,
    ownLateral,
    ownSpeed,
    hint
) {
    if (driver.defendTarget) {
        if (
            !defendSeen ||
            driver.defendTarget.recovering ||
            defendGap > 1 ||
            defendGap < -34 ||
            driver.defendTicks >= DEFEND_MAX_TICKS ||
            sim.offSurface
        ) {
            clearDefence(driver, 240);
        }
        return;
    }

    if (
        !rearSim ||
        driver.defendCooldown > 0 ||
        driver.passTarget ||
        sim.offSurface ||
        sim.recovering ||
        ownSpeed < 12
    ) {
        return;
    }

    const closingSpeed = rearSpeed - ownSpeed;
    if (closingSpeed <= 1.5) return;

    const timeToArrival = -rearGap / closingSpeed;
    if (timeToArrival > 2.6 || Math.abs(sim.track.raceCurv[hint]) > 0.04) return;

    const turnSide = futureTurnSide(sim.track, hint);
    const threatSide = signNonZero(rearLateral - ownLateral);
    let side = turnSide || threatSide || driverBias(opts);
    const base = sim.track.raceOffset[hint] + (opts.lineOffset ?? 0);

    if (!laneFits(sim.track, hint, base + side * DEFEND_OFFSET, 0.35)) {
        side *= -1;
    }
    if (!laneFits(sim.track, hint, base + side * DEFEND_OFFSET, 0.35)) return;

    driver.defendTarget = rearSim;
    driver.defendSide = side;
    driver.defendTicks = 0;
}

/**
 * Score both usable lanes without sorting or allocating. Room around other cars,
 * braking-side positioning and the driver's stable bias decide ties.
 */
function choosePassSide(sim, opts, leader, leaderLateral, ownLateral, hint) {
    const track = sim.track;
    const base = track.raceOffset[hint] + (opts.lineOffset ?? 0);
    const turnSide = futureTurnSide(track, hint);
    const bias = driverBias(opts);

    let bestSide = 0;
    let bestScore = -Infinity;

    for (let side = -1; side <= 1; side += 2) {
        const candidate = base + side * PASS_OFFSET;
        if (!laneFits(track, hint, candidate, 0.25)) continue;

        const futureIndex = indexAhead(track, hint, 28);
        const futureCandidate =
            track.raceOffset[futureIndex] + (opts.lineOffset ?? 0) + side * PASS_OFFSET;
        if (!laneFits(track, futureIndex, futureCandidate, 0.15)) continue;

        let score = (laneLimit(track, hint) - Math.abs(candidate)) * 0.35;
        score += Math.abs(candidate - leaderLateral) * 0.22;
        if (side === turnSide) score += 0.7;
        if (side === bias) score += 0.12;

        const others = opts.others;
        if (others) {
            const ownProgress = progressOf(sim, hint);
            for (let i = 0; i < others.length; i++) {
                const other = others[i];
                if (!other || other === sim || other === leader || other.recovering) {
                    continue;
                }
                const otherHint = normalizeIndex(other.trackIndexHint ?? 0, track.count);
                const gap = relativeGap(
                    ownProgress,
                    progressOf(other, otherHint),
                    track.length
                );
                if (gap < -4 || gap > 25) continue;

                const clearance = Math.abs(
                    candidate - lateralPosition(other, otherHint)
                );
                if (clearance < 2.35) {
                    score -= 2.5 + (2.35 - clearance) * 4;
                }
            }
        }

        if (score > bestScore) {
            bestScore = score;
            bestSide = side;
        }
    }

    // A fully occupied lane should make the driver queue rather than force a gap.
    return bestScore > -1 ? bestSide : 0;
}

/**
 * Dynamic braking envelope: for every relevant point ahead, calculate the
 * maximum speed from which the car can still reach that point's corner limit.
 */
function brakingEnvelopeSpeed(track, plan, hint, speed, pace, linePenalty) {
    const cruiseSpeed = Math.max(4.5, 76 * pace);
    let allowedSq = cruiseSpeed * cruiseSpeed;
    const lookDistance = clamp(105 + speed * 1.65, 115, 220);
    const reactionDistance = 3 + speed * 0.12;

    for (let distance = 0; distance <= lookDistance; distance += track.spacing) {
        const index = indexAhead(track, hint, distance);
        let cornerSpeed = plan.cornerSpeeds[index] * pace;
        if (Math.abs(track.raceCurv[index]) > 0.008) {
            cornerSpeed *= linePenalty;
        }

        const brakingDistance = Math.max(0, distance - reactionDistance);
        const candidateSq =
            cornerSpeed * cornerSpeed + 2 * BRAKE_DECEL * brakingDistance;
        if (candidateSq < allowedSq) allowedSq = candidateSq;
    }

    return Math.sqrt(Math.max(0, allowedSq));
}

function trackPlanFor(track) {
    let plan = TRACK_PLANS.get(track);
    if (plan) return plan;

    const cornerSpeeds = new Float32Array(track.count);
    for (let i = 0; i < track.count; i++) {
        let peak = 0;
        for (let n = -2; n <= 2; n++) {
            peak = Math.max(
                peak,
                Math.abs(track.raceCurv[normalizeIndex(i + n, track.count)])
            );
        }
        cornerSpeeds[i] = cornerSpeedForCurvature(peak);
    }

    plan = { cornerSpeeds };
    TRACK_PLANS.set(track, plan);
    return plan;
}

function cornerSpeedForCurvature(curvature) {
    const peak = Math.max(curvature, 1e-4);
    const needAngle = Math.atan(3.6 * peak * 1.2);
    const geometryLimit = Math.max(4, (0.58 / needAngle - 1) / 0.075);
    return Math.max(
        4.5,
        Math.min(76, Math.sqrt(18 / peak), geometryLimit)
    );
}

function driverStateFor(sim) {
    let driver = DRIVER_STATES.get(sim);
    if (driver) return driver;

    driver = {
        passTarget: null,
        passSide: 0,
        passTicks: 0,
        passCooldown: 0,
        defendTarget: null,
        defendSide: 0,
        defendTicks: 0,
        defendCooldown: 0,
        maneuverOffset: 0,
        lastTick: Number.isFinite(sim._simTick) ? sim._simTick : 0,
        lastX: sim.state.x,
        lastZ: sim.state.z,
    };
    DRIVER_STATES.set(sim, driver);
    return driver;
}

function refreshDriverState(driver, sim) {
    const tick = Number.isFinite(sim._simTick) ? sim._simTick : driver.lastTick;
    const dx = sim.state.x - driver.lastX;
    const dz = sim.state.z - driver.lastZ;
    if (tick < driver.lastTick || dx * dx + dz * dz > 2500) {
        clearPass(driver, 0);
        clearDefence(driver, 0);
        driver.maneuverOffset = 0;
        driver.passCooldown = 0;
        driver.defendCooldown = 0;
    }
}

function clearPass(driver, cooldown) {
    driver.passTarget = null;
    driver.passSide = 0;
    driver.passTicks = 0;
    driver.passCooldown = Math.max(driver.passCooldown, cooldown);
}

function clearDefence(driver, cooldown) {
    driver.defendTarget = null;
    driver.defendSide = 0;
    driver.defendTicks = 0;
    driver.defendCooldown = Math.max(driver.defendCooldown, cooldown);
}

function futureTurnSide(track, hint) {
    let strongest = 0;
    for (let distance = 20; distance <= 100; distance += track.spacing * 2) {
        const curvature = track.raceCurv[indexAhead(track, hint, distance)];
        if (Math.abs(curvature) > Math.abs(strongest)) strongest = curvature;
    }
    return Math.abs(strongest) < 0.006 ? 0 : signNonZero(strongest);
}

function laneFits(track, index, offset, reserve) {
    return Math.abs(offset) <= laneLimit(track, index) - reserve;
}

function laneLimit(track, index) {
    return Math.max(0.6, track.halfWidths[index] - 1.2);
}

function progressOf(sim, hint) {
    const progress = sim.lastProgress;
    if (Number.isFinite(progress)) return progress;
    return hint / sim.track.count;
}

function lateralPosition(sim, hint) {
    const track = sim.track;
    return (
        (sim.state.x - track.xs[hint]) * track.nx[hint] +
        (sim.state.z - track.zs[hint]) * track.nz[hint]
    );
}

function relativeGap(ownProgress, otherProgress, trackLength) {
    let gap = (otherProgress - ownProgress) * trackLength;
    const halfLength = trackLength * 0.5;
    if (gap > halfLength) gap -= trackLength;
    if (gap < -halfLength) gap += trackLength;
    return gap;
}

function indexAhead(track, hint, distance) {
    return normalizeIndex(hint + Math.round(distance / track.spacing), track.count);
}

function normalizeIndex(index, count) {
    const wrapped = index % count;
    return wrapped < 0 ? wrapped + count : wrapped;
}

function driverBias(opts) {
    const lineOffset = opts.lineOffset ?? 0;
    if (Math.abs(lineOffset) > 0.01) return signNonZero(lineOffset);
    const lookBias = opts.lookBias ?? 0;
    if (Math.abs(lookBias) > 0.01) return signNonZero(lookBias);
    return 1;
}

function signNonZero(value) {
    return value < 0 ? -1 : 1;
}

function wrapAngle(angle) {
    angle %= TWO_PI;
    if (angle > Math.PI) return angle - TWO_PI;
    if (angle < -Math.PI) return angle + TWO_PI;
    return angle;
}

function clamp(value, min, max) {
    return value < min ? min : value > max ? max : value;
}
