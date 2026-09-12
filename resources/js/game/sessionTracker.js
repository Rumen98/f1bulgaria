/** Gameplay observations, separate from the verified leaderboard and simulation. */

/**
 * crypto.randomUUID is missing on older WebViews and on plain-http origins
 * (insecure context) — the fallback keeps the session id unique enough for
 * the (user_id, client_id) key without blocking the game start.
 */
export function randomClientId() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    const bytes = new Uint8Array(16);
    if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
        crypto.getRandomValues(bytes);
    } else {
        for (let i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256);
    }
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export function createSessionTracker({ createSession, sendEvents, now = () => performance.now(), uuid = randomClientId, onSession = () => {} }) {
    let current = null;
    const pending = new Set();
    const terminalEvents = new Set(['lap_completed', 'race_completed', 'quit', 'restarted', 'page_left', 'error']);
    const disposableEvents = new Set(['heartbeat', 'paused', 'resumed', 'page_hidden']);

    const elapsed = (run) => Math.min(14_400_000, Math.round(run.activeMs + (run.paused || run.ended ? 0 : Math.max(0, now() - run.clock))));

    function acknowledge(run, batch) {
        const sent = new Set(batch.map((event) => event.sequence));
        run.queue = run.queue.filter((event) => !sent.has(event.sequence));
        if (!run.queue.length) pending.delete(run);
    }

    function flush(run, keepalive = false) {
        run.keepalive ||= keepalive;
        if (!run.queue.length) return run.sending ?? Promise.resolve();

        // An in-flight fetch cannot be upgraded at pagehide. Duplicate the latest
        // bounded batch with keepalive; the server deduplicates its sequence IDs.
        if (keepalive && run.id && run.sending && run.urgentSequence < run.sequence) {
            const batch = run.queue.slice(-30);
            const lastSequence = run.sequence;
            run.urgentSequence = lastSequence;
            const urgent = Promise.resolve().then(() => sendEvents(run.id, batch, true))
                .then(() => acknowledge(run, batch))
                .catch(() => {
                    if (run.urgentSequence === lastSequence) run.urgentSequence = 0;
                })
                .finally(() => run.urgent.delete(urgent));
            run.urgent.add(urgent);
        }

        if (!run.sending) {
            run.sending = Promise.resolve().then(async () => {
                if (!run.id) {
                    const response = await createSession(run.metadata);
                    if (!response?.id) throw new Error('Session ID is missing');
                    run.id = response.id;
                    try { onSession(run.id, run === current); } catch { /* UI callbacks cannot prevent delivery. */ }
                }
                while (run.queue.length) {
                    const batch = run.queue.slice(0, 30);
                    await sendEvents(run.id, batch, run.keepalive);
                    acknowledge(run, batch);
                }
            }).catch(() => {
                // Retry the same client ID and sequence numbers without interrupting driving.
            }).finally(() => { run.sending = null; });
        }

        return Promise.all([run.sending, ...run.urgent]);
    }

    function record(type, data = {}, run = current, keepalive = false) {
        if (!run || run.sequence >= 4096 || (run.sequence >= 4000 && disposableEvents.has(type))) return;
        // A prolonged offline session cannot retain an unbounded event queue.
        if (run.queue.length >= 300) {
            let discard = run.queue.findIndex((event) => event.type === 'heartbeat');
            if (discard < 0) discard = run.queue.findIndex((event) => disposableEvents.has(event.type));
            if (discard < 0 && !disposableEvents.has(type)) {
                discard = run.queue.findIndex((event) => event.type !== 'started' && !terminalEvents.has(event.type));
            }
            if (discard < 0) return;
            run.queue.splice(discard, 1);
        }
        run.queue.push({ sequence: ++run.sequence, type, active_ms: elapsed(run), data: { ...run.snapshot, ...data } });
        pending.add(run);
        void flush(run, keepalive);
    }

    function flushPending(keepalive = false, except = null) {
        return Promise.all([...pending].filter((run) => run !== except).map((run) => flush(run, keepalive)));
    }

    function end(type = 'quit', data = {}, keepalive = false) {
        // Finishing does not mean the server has received the finish. Preserve
        // pending results from this and previous attempts when the page leaves.
        if (keepalive) void flushPending(true, current?.ended ? null : current);
        if (!current || current.ended) return;
        current.activeMs = elapsed(current);
        current.ended = true;
        current.snapshot = { ...current.snapshot, ...data };
        record(type, data, current, keepalive);
    }

    function start(metadata) {
        end('restarted');
        current = {
            id: null, metadata: { ...metadata, context: { ...metadata.context }, client_id: uuid() }, queue: [], sequence: 0,
            clock: now(), activeMs: 0, paused: false, ended: false, sending: null,
            keepalive: false, urgent: new Set(), urgentSequence: 0,
            moved: false, sectors: new Set(), invalid: false, laps: 0, hidden: false, crossedLine: false, lastProgress: 0,
            snapshot: { progress: 0, max_speed: 0, lap_number: 0 },
        };
        record('started');
        return current;
    }

    function observe(values) {
        const run = current;
        if (!run || run.ended || run.paused) return;
        // Three decimals: the server validates `decimal:0,4`, and JSON would
        // otherwise serialise a near-zero speed as 3e-7, which is not a decimal.
        const finite = (value, min, max, fallback = 0) => Number.isFinite(value) ? Math.round(Math.min(max, Math.max(min, value)) * 1000) / 1000 : fallback;
        const phase = ['formation', 'flying', 'finished'].includes(values.phase) ? values.phase : 'formation';
        const sector = Math.round(finite(values.sector, 1, 3, 1));
        // Progress counts in the out-lap too: a player who quits half-way
        // through it did leave the line. The race grid sits just BEFORE the
        // line (progress ≈ 0.97–0.999): until the car has crossed it once,
        // that tail is the start, not 99 %. Solo starts on the line.
        const trackProgress = finite(values.trackProgress, 0, 1);
        if (run.lastProgress > 0.85 && trackProgress < 0.15) run.crossedLine = true;
        run.lastProgress = trackProgress;
        const beyondGrid = run.metadata.mode === 'solo' || run.crossedLine || trackProgress < 0.9;
        run.snapshot = {
            phase, sector, progress: phase === 'flying' || beyondGrid ? trackProgress : 0,
            speed: finite(values.speed, 0, 500), max_speed: Math.max(run.snapshot.max_speed, finite(values.speed, 0, 500)),
            frame_ms: finite(values.frameMs, 0, 10_000), render_scale: finite(values.renderScale, 0, 2, 1),
            quality_tier: ['low-power', 'auto-full', 'auto-balanced', 'auto-safe', 'manual'].includes(values.qualityTier) ? values.qualityTier : 'manual',
            lap_valid: values.lapValid !== false, warnings: Math.round(finite(values.warnings, 0, 100)), lap_number: run.laps,
        };
        if (!run.moved && run.snapshot.speed >= 3) {
            run.moved = true;
            record('moving');
        }
        if (phase === 'flying' && !run.sectors.has(sector)) {
            run.sectors.add(sector);
            record('sector');
        }
        if (phase === 'flying' && values.lapValid === false && !run.invalid) {
            run.invalid = true;
            record('lap_invalidated');
        }
    }

    function lapCompleted(lap) {
        const run = current;
        if (!run || run.ended || run.laps >= 100) return;
        // The first race lap is untimed by the sim (out-lap from the grid) but
        // it is lap 1/3 for the player: it counts, with lap_ms null.
        const untimed = lap.untimed === true && lap.lapMs === null;
        if (!untimed && (!Number.isFinite(lap.lapMs) || lap.lapMs <= 0)) return;
        run.laps++;
        const lapMs = untimed ? null : Math.min(14_400_000, Math.max(1, Math.round(lap.lapMs)));
        const data = { lap_ms: lapMs, lap_valid: lap.valid === true, lap_number: run.laps, progress: 1, sector: 3 };
        if (run.metadata.mode === 'solo') {
            end('lap_completed', data);
        } else {
            record('lap_completed', data);
            run.invalid = false;
            run.sectors.clear();
            run.snapshot = { ...run.snapshot, progress: 0, sector: 1, lap_number: run.laps, lap_valid: true, warnings: 0 };
        }
    }

    function pause(paused, type = paused ? 'paused' : 'resumed') {
        const run = current;
        if (type === 'page_hidden') void flushPending(true, run?.ended ? null : run);
        if (!run || run.ended) return;
        if (type === 'page_hidden' && run.hidden) return;
        run.hidden = type === 'page_hidden' || (paused && run.hidden);
        if (paused === run.paused) {
            if (type === 'page_hidden') record(type, {}, run, true);
            return;
        }
        run.activeMs = elapsed(run);
        run.clock = now();
        run.paused = paused;
        record(type, {}, run, type === 'page_hidden');
    }

    function heartbeat() {
        if (current && !current.ended) record('heartbeat');
        for (const run of pending) void flush(run);
    }

    return { start, observe, lapCompleted, pause, end, record, heartbeat, get current() { return current; }, flush: () => flushPending() };
}

/** Same-origin, authenticated delivery; no third-party analytics or raw browser fingerprint. */
export async function sendSessionEvents(id, events, keepalive = false) {
    const token = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='));
    const response = await fetch(`/game/session/${id}/events`, {
        method: 'POST', credentials: 'same-origin', keepalive,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token.slice(11)) } : {}) },
        body: JSON.stringify({ events }),
    });
    if (!response.ok) throw new Error('Session telemetry was not accepted');
}

export function sessionDeviceContext() {
    const ua = navigator.userAgent;
    const ios = /iPhone|iPad|iPod/i.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
    return {
        viewport_width: Math.min(10_000, window.innerWidth), viewport_height: Math.min(10_000, window.innerHeight),
        // Три десетични: Windows 125 % дава 1.25, 133 % — 1.333333…, а сървърът
        // валидира decimal:0,4; иначе стартът на сесията гърми с 422.
        pixel_ratio: Math.round(Math.min(10, window.devicePixelRatio || 1) * 1000) / 1000,
        browser: /Edg/.test(ua) ? 'edge' : /Firefox|FxiOS/.test(ua) ? 'firefox' : /Chrome|CriOS/.test(ua) ? 'chrome' : /Safari/.test(ua) ? 'safari' : 'other',
        os: ios ? 'ios' : /Android/.test(ua) ? 'android' : /Windows/.test(ua) ? 'windows' : /Macintosh/.test(ua) ? 'macos' : /Linux/.test(ua) ? 'linux' : 'other',
    };
}
