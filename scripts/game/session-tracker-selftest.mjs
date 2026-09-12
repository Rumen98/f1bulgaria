/** Regression tests for gameplay observations, independent of the verified leaderboard. */
import assert from 'node:assert/strict';
import { createSessionTracker, sendSessionEvents, sessionDeviceContext } from '../../resources/js/game/sessionTracker.js';

const tick = async () => { for (let i = 0; i < 12; i++) await Promise.resolve(); };
const deferred = () => {
    let resolve;
    let reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
};

function harness(overrides = {}) {
    let clock = 0;
    let id = 0;
    const batches = [];
    const created = [];
    const callbacks = [];
    const tracker = createSessionTracker({
        now: () => clock,
        uuid: () => `attempt-${++id}`,
        createSession: async (metadata) => {
            created.push(structuredClone(metadata));
            return { id: metadata.client_id };
        },
        sendEvents: async (session, events, keepalive) => { batches.push({ session, events: structuredClone(events), keepalive }); },
        onSession: (session, latest) => callbacks.push({ session, latest }),
        ...overrides,
    });
    return {
        tracker, batches, created, callbacks,
        advance: (ms) => { clock += ms; },
        events: (session) => batches.filter((batch) => !session || batch.session === session).flatMap((batch) => batch.events),
    };
}

const metadata = { mode: 'solo', track: 'monza', device: 'mobile', context: { controls: 'buttons' } };
const sample = { phase: 'flying', sector: 1, trackProgress: 0.12, speed: 120, frameMs: 17, qualityTier: 'auto-balanced', lapValid: true };
let checks = 0;
async function check(name, fn) {
    await fn();
    checks++;
    console.log(`✓ ${name}`);
}

await check('failed creation retries the same client ID and all events without blocking the game', async () => {
    const creations = [];
    const h = harness({ createSession: async (value) => {
        creations.push(structuredClone(value));
        if (creations.length === 1) throw new Error('offline');
        return { id: 41 };
    } });
    h.tracker.start(metadata);
    await h.tracker.flush();
    h.tracker.observe(sample);
    await h.tracker.flush();
    assert.equal(creations.length, 2);
    assert.deepEqual(creations[0], creations[1]);
    assert.deepEqual(h.events().map((event) => event.type), ['started', 'moving', 'sector']);
    assert.deepEqual(h.events().map((event) => event.sequence), [1, 2, 3]);
});

await check('lost delivery acknowledgement retries identical sequences, with serialized batches of at most 30', async () => {
    const batches = [];
    let fail = true;
    let inFlight = 0;
    let peak = 0;
    const h = harness({ sendEvents: async (id, events) => {
        peak = Math.max(peak, ++inFlight);
        batches.push(structuredClone(events));
        await tick();
        inFlight--;
        if (fail) { fail = false; throw new Error('response lost after server acceptance'); }
    } });
    h.tracker.start(metadata);
    await h.tracker.flush();
    for (let i = 0; i < 75; i++) h.tracker.heartbeat();
    await h.tracker.flush();
    assert.equal(peak, 1);
    assert.equal(batches[0][0].sequence, batches[1][0].sequence);
    assert(batches.every((batch) => batch.length <= 30));
    const unique = new Set(batches.flat().map((event) => event.sequence));
    assert.equal(unique.size, 76);
    assert.equal(h.tracker.current.queue.length, 0);
});

await check('a delayed creation preserves final events, and a later attempt has its own clock and ID', async () => {
    const firstCreation = deferred();
    let creations = 0;
    const h = harness({ createSession: () => ++creations === 1 ? firstCreation.promise : Promise.resolve({ id: 'second' }) });
    const first = h.tracker.start(metadata);
    await tick();
    h.advance(5_000);
    h.tracker.end('page_left', {}, true);
    const second = h.tracker.start(metadata);
    await tick();
    firstCreation.resolve({ id: 'first' });
    await h.tracker.flush();
    assert.notEqual(first.metadata.client_id, second.metadata.client_id);
    assert.deepEqual(h.events('first').map((event) => event.type), ['started', 'page_left']);
    assert.equal(h.events('first').at(-1).active_ms, 5_000);
    assert(h.batches.filter((batch) => batch.session === 'first').every((batch) => batch.keepalive));
    assert.equal(h.events('second')[0].active_ms, 0);
    assert.deepEqual(h.callbacks, [{ session: 'second', latest: true }, { session: 'first', latest: false }]);
});

await check('page exit immediately delivers a keepalive terminal batch while an ordinary request is still pending', async () => {
    const ordinary = deferred();
    const batches = [];
    const h = harness({ sendEvents: (id, events, keepalive) => {
        batches.push({ events: structuredClone(events), keepalive });
        return keepalive ? Promise.resolve() : ordinary.promise;
    } });
    h.tracker.start(metadata);
    await tick();
    assert.equal(batches.length, 1);
    h.tracker.end('page_left', {}, true);
    await tick();
    assert.equal(batches.length, 2);
    assert.equal(batches[1].keepalive, true);
    assert.deepEqual(batches[1].events.map((event) => event.type), ['started', 'page_left']);
    assert.equal(batches[1].events[0].sequence, batches[0].events[0].sequence);
    ordinary.resolve();
    await h.tracker.flush();
    assert.equal(h.tracker.current.queue.length, 0);
});

await check('closing or hiding the result screen preserves an in-flight finish without changing its outcome', async () => {
    for (const leave of [
        (tracker) => tracker.end('page_left', {}, true),
        (tracker) => tracker.pause(true, 'page_hidden'),
    ]) {
        const finishDelivery = deferred();
        const batches = [];
        const h = harness({ sendEvents: (id, events, keepalive) => {
            batches.push({ events: structuredClone(events), keepalive });
            return events.some((event) => event.type === 'lap_completed') && !keepalive ? finishDelivery.promise : Promise.resolve();
        } });
        h.tracker.start(metadata);
        await h.tracker.flush();
        h.advance(120_000);
        h.tracker.lapCompleted({ lapMs: 115_000, valid: false });
        await tick();
        assert.equal(h.tracker.current.ended, true);
        assert.equal(batches.at(-1).keepalive, false);
        leave(h.tracker);
        await tick();
        const savedFinish = batches.at(-1);
        assert.equal(savedFinish.keepalive, true);
        assert.equal(savedFinish.events.at(-1).type, 'lap_completed');
        assert.equal(savedFinish.events.at(-1).data.lap_valid, false);
        assert.equal(savedFinish.events.at(-1).active_ms, 120_000);
        assert.equal(batches.flatMap((batch) => batch.events).some((event) => ['page_left', 'page_hidden'].includes(event.type)), false);
        finishDelivery.resolve();
        await h.tracker.flush();
        assert.equal(h.tracker.current.queue.length, 0);
    }
});

await check('leaving a later attempt also preserves undelivered results from an earlier attempt', async () => {
    const earlierDelivery = deferred();
    const batches = [];
    const h = harness({ sendEvents: (id, events, keepalive) => {
        batches.push({ id, events: structuredClone(events), keepalive });
        return id === 'attempt-1' && events.some((event) => event.type === 'lap_completed') && !keepalive ? earlierDelivery.promise : Promise.resolve();
    } });
    const first = h.tracker.start(metadata);
    await h.tracker.flush();
    h.tracker.lapCompleted({ lapMs: 100_000, valid: true });
    await tick();
    const second = h.tracker.start(metadata);
    await tick();
    h.tracker.end('page_left', {}, true);
    await tick();
    assert(batches.some((batch) => batch.id === first.id && batch.keepalive && batch.events.some((event) => event.type === 'lap_completed')));
    assert(batches.some((batch) => batch.id === second.id && batch.keepalive && batch.events.some((event) => event.type === 'page_left')));
    assert.equal(batches.filter((batch) => batch.id === first.id).flatMap((batch) => batch.events).some((event) => event.type === 'page_left'), false);
    earlierDelivery.resolve();
    await h.tracker.flush();
    assert.equal(first.queue.length, 0);
    assert.equal(second.queue.length, 0);
});

await check('active driving time excludes manual pause and hidden-page time', async () => {
    const h = harness();
    h.tracker.start(metadata);
    h.advance(2_000);
    h.tracker.pause(true);
    h.advance(50_000);
    h.tracker.pause(true, 'page_hidden');
    h.tracker.pause(true, 'page_hidden');
    h.advance(50_000);
    h.tracker.heartbeat();
    h.tracker.pause(false);
    h.advance(3_000);
    h.tracker.end();
    await h.tracker.flush();
    assert.equal(h.events().filter((event) => event.type === 'page_hidden').length, 1);
    assert.equal(h.events().find((event) => event.type === 'resumed').active_ms, 2_000);
    assert.equal(h.events().at(-1).active_ms, 5_000);
});

await check('formation is not 99% completion; backwards sector movement and invalid frames do not spam milestones', async () => {
    const h = harness();
    h.tracker.start(metadata);
    h.tracker.observe({ ...sample, phase: 'formation', trackProgress: 0.999 });
    h.tracker.heartbeat();
    // Half an out-lap is real progress; the grid slot just before the line is not.
    h.tracker.observe({ ...sample, phase: 'formation', trackProgress: 0.42 });
    h.tracker.heartbeat();
    // Solo starts ON the line: 95 % of the out-lap is real progress too.
    h.tracker.observe({ ...sample, phase: 'formation', trackProgress: 0.95 });
    h.tracker.heartbeat();
    for (const sector of [1, 2, 1, 2, 3, 2, 3]) h.tracker.observe({ ...sample, sector, lapValid: false });
    await h.tracker.flush();
    assert.equal(h.events().find((event) => event.type === 'heartbeat').data.progress, 0.999);
    assert.equal(h.events().filter((event) => event.type === 'heartbeat')[1].data.progress, 0.42);
    assert.equal(h.events().filter((event) => event.type === 'heartbeat')[2].data.progress, 0.95);

    // Race grid: the tail before the line is 0 until the first crossing, then real.
    const race = harness();
    race.tracker.start({ ...metadata, mode: 'race' });
    race.tracker.observe({ ...sample, phase: 'formation', trackProgress: 0.993 });
    race.tracker.heartbeat();
    race.tracker.observe({ ...sample, phase: 'formation', trackProgress: 0.02 });
    race.tracker.observe({ ...sample, phase: 'formation', trackProgress: 0.95 });
    race.tracker.heartbeat();
    await race.tracker.flush();
    const beats = race.events().filter((event) => event.type === 'heartbeat');
    assert.equal(beats[0].data.progress, 0);
    assert.equal(beats[1].data.progress, 0.95);
    assert.deepEqual(h.events().filter((event) => event.type === 'sector').map((event) => event.data.sector), [1, 2, 3]);
    assert.equal(h.events().filter((event) => event.type === 'lap_invalidated').length, 1);
    assert.equal(h.events().filter((event) => event.type === 'moving').length, 1);
    assert.equal(h.events().at(-1).data.quality_tier, 'auto-balanced');
});

await check('the untimed first race lap counts as lap 1 with a null time; untimed is not accepted for solo', async () => {
    const h = harness();
    h.tracker.start({ ...metadata, mode: 'race' });
    h.tracker.lapCompleted({ lapMs: null, valid: true, untimed: true });
    h.tracker.lapCompleted({ lapMs: 100_000, valid: true, untimed: false });
    h.tracker.lapCompleted({ lapMs: 99_000, valid: false, untimed: false });
    h.tracker.end('race_completed', { progress: 1, race_position: 4 });
    await h.tracker.flush();
    const laps = h.events().filter((event) => event.type === 'lap_completed');
    assert.deepEqual(laps.map((event) => event.data.lap_number), [1, 2, 3]);
    assert.deepEqual(laps.map((event) => event.data.lap_ms), [null, 100_000, 99_000]);
    assert.deepEqual(laps.map((event) => event.data.lap_valid), [true, true, false]);
    assert.equal(h.events().at(-1).data.lap_number, 3);

    // Without the explicit flag a null time is still garbage, not a lap.
    const solo = harness();
    solo.tracker.start(metadata);
    solo.tracker.lapCompleted({ lapMs: null, valid: true });
    await solo.tracker.flush();
    assert.equal(solo.events().filter((event) => event.type === 'lap_completed').length, 0);
});

await check('client ids are v4 UUIDs even without crypto.randomUUID', async () => {
    const { randomClientId } = await import('../../resources/js/game/sessionTracker.js');
    const original = globalThis.crypto.randomUUID;
    try {
        Object.defineProperty(globalThis.crypto, 'randomUUID', { value: undefined, configurable: true });
        const ids = new Set(Array.from({ length: 50 }, () => randomClientId()));
        assert.equal(ids.size, 50);
        for (const id of ids) assert.match(id, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    } finally {
        Object.defineProperty(globalThis.crypto, 'randomUUID', { value: original, configurable: true });
    }
    assert.match(randomClientId(), /^[0-9a-f-]{36}$/);
});

await check('explicit equal-time race lap callbacks both count, with new milestones for each lap', async () => {
    const h = harness();
    h.tracker.start({ ...metadata, mode: 'race' });
    h.tracker.observe({ ...sample, lapValid: false });
    h.tracker.lapCompleted({ lapMs: 100_000, valid: false });
    assert.equal(h.tracker.current.ended, false);
    h.tracker.observe({ ...sample, lapValid: false });
    h.tracker.lapCompleted({ lapMs: 100_000, valid: true });
    h.tracker.end('race_completed', { progress: 1, race_position: 2 });
    h.tracker.end('quit');
    await h.tracker.flush();
    const laps = h.events().filter((event) => event.type === 'lap_completed');
    assert.deepEqual(laps.map((event) => event.data.lap_number), [1, 2]);
    assert.deepEqual(laps.map((event) => event.data.lap_valid), [false, true]);
    assert.equal(h.events().filter((event) => event.type === 'sector').length, 2);
    assert.equal(h.events().filter((event) => event.type === 'lap_invalidated').length, 2);
    assert.equal(h.events().at(-1).type, 'race_completed');
    assert.equal(h.events().at(-1).data.lap_number, 2);
});

await check('solo completion is terminal, replay frames are ignored, and delayed save results remain on the original attempt', async () => {
    const h = harness();
    const first = h.tracker.start(metadata);
    h.advance(100_000);
    h.tracker.lapCompleted({ lapMs: 100_000, valid: false });
    h.tracker.observe({ ...sample, sector: 3 });
    h.tracker.lapCompleted({ lapMs: 100_000, valid: false });
    h.tracker.end('quit');
    const second = h.tracker.start(metadata);
    h.advance(20_000);
    h.tracker.record('lap_save_failed', { save_status: 'failure' }, first);
    await h.tracker.flush();
    const oldEvents = h.events(first.id);
    assert.deepEqual(oldEvents.map((event) => event.type), ['started', 'lap_completed', 'lap_save_failed']);
    assert.equal(oldEvents.at(-1).active_ms, 100_000);
    assert.equal(oldEvents.at(-1).data.progress, 1);
    assert.equal(oldEvents.at(-1).data.lap_valid, false);
    assert.deepEqual(h.events(second.id).map((event) => event.type), ['started']);
});

await check('restart records the unfinished attempt and resets per-attempt milestones', async () => {
    const h = harness();
    const first = h.tracker.start(metadata);
    h.tracker.observe(sample);
    h.advance(7_000);
    const second = h.tracker.start(metadata);
    h.tracker.observe(sample);
    await h.tracker.flush();
    assert.equal(h.events(first.id).at(-1).type, 'restarted');
    assert.equal(h.events(first.id).at(-1).active_ms, 7_000);
    assert.deepEqual(h.events(second.id).map((event) => event.type), ['started', 'moving', 'sector']);
    assert.equal(h.events(second.id)[0].sequence, 1);
});

await check('an offline event queue stays bounded and preserves completion after excessive heartbeats', async () => {
    let offline = true;
    const h = harness({ createSession: async () => {
        if (offline) throw new Error('offline');
        return { id: 'recovered' };
    } });
    h.tracker.start(metadata);
    for (let i = 0; i < 5_000; i++) h.tracker.heartbeat();
    h.tracker.end('race_completed', { progress: 1, race_position: 6 });
    assert(h.tracker.current.queue.length <= 300);
    assert.equal(h.tracker.current.queue[0].type, 'started');
    assert.equal(h.tracker.current.queue.at(-1).type, 'race_completed');
    assert(h.tracker.current.queue.at(-1).sequence <= 4096);
    await h.tracker.flush();
    offline = false;
    await h.tracker.flush();
    assert.equal(h.events().at(-1).type, 'race_completed');
});

await check('invalid samples cannot create NaN metrics or invalid lap durations', async () => {
    const h = harness({ onSession: () => { throw new Error('UI callback failure'); } });
    h.tracker.start(metadata);
    h.tracker.observe({ ...sample, speed: NaN, frameMs: Infinity, trackProgress: Infinity, renderScale: NaN, qualityTier: 'unknown' });
    h.tracker.lapCompleted({ lapMs: NaN, valid: true });
    h.tracker.lapCompleted({ lapMs: 0, valid: true });
    h.tracker.end();
    await h.tracker.flush();
    assert.equal(h.events().filter((event) => event.type === 'lap_completed').length, 0);
    const data = h.events().at(-1).data;
    assert.equal(data.speed, 0);
    assert.equal(data.frame_ms, 0);
    assert.equal(data.progress, 0);
    assert.equal(data.render_scale, 1);
    assert.equal(data.quality_tier, 'manual');
});

await check('HTTP delivery uses same-origin CSRF credentials and propagates rejected requests to the retry layer', async () => {
    const previousFetch = globalThis.fetch;
    const previousDocument = Object.getOwnPropertyDescriptor(globalThis, 'document');
    try {
        Object.defineProperty(globalThis, 'document', { configurable: true, value: { cookie: 'other=x; XSRF-TOKEN=signed%3Dtoken' } });
        let captured;
        globalThis.fetch = async (url, options) => { captured = { url, options }; return { ok: true }; };
        await sendSessionEvents(42, [{ sequence: 1, type: 'page_left' }], true);
        assert.equal(captured.url, '/game/session/42/events');
        assert.equal(captured.options.credentials, 'same-origin');
        assert.equal(captured.options.keepalive, true);
        assert.equal(captured.options.headers['X-XSRF-TOKEN'], 'signed=token');
        assert.equal(JSON.parse(captured.options.body).events[0].type, 'page_left');
        globalThis.fetch = async () => ({ ok: false });
        await assert.rejects(sendSessionEvents(42, []), /not accepted/);
    } finally {
        globalThis.fetch = previousFetch;
        if (previousDocument) Object.defineProperty(globalThis, 'document', previousDocument);
        else delete globalThis.document;
    }
});

await check('device context reports coarse iPhone/iPad browser data without storing the raw user agent', async () => {
    const previousNavigator = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
    const previousWindow = Object.getOwnPropertyDescriptor(globalThis, 'window');
    try {
        Object.defineProperty(globalThis, 'navigator', { configurable: true, value: { userAgent: 'Mozilla Macintosh AppleWebKit Version/17 Safari/605', maxTouchPoints: 5 } });
        Object.defineProperty(globalThis, 'window', { configurable: true, value: { innerWidth: 844, innerHeight: 390, devicePixelRatio: 3 } });
        const context = sessionDeviceContext();
        assert.equal(context.os, 'ios');
        assert.equal(context.browser, 'safari');
        assert.equal(context.viewport_width, 844);
        assert.equal(context.pixel_ratio, 3);
        assert.equal('user_agent' in context, false);

        // Windows 133 % scaling: 1.3333333333333333 must not reach the server
        // (decimal:0,4 would reject the whole session start).
        Object.defineProperty(globalThis, 'window', { configurable: true, value: { innerWidth: 1920, innerHeight: 1080, devicePixelRatio: 4 / 3 } });
        assert.equal(sessionDeviceContext().pixel_ratio, 1.333);
    } finally {
        if (previousNavigator) Object.defineProperty(globalThis, 'navigator', previousNavigator);
        else delete globalThis.navigator;
        if (previousWindow) Object.defineProperty(globalThis, 'window', previousWindow);
        else delete globalThis.window;
    }
});

console.log(`Session tracker: ${checks} regression scenarios passed.`);
