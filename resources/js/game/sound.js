/**
 * Звук на двигателя — СИНТЕЗ в Web Audio, не семплиран loop.
 *
 * Предишният опит (семплиран откъс) звучеше като „забила предавка", защото
 * питчването на дълъг запис не следва оборотите точно. Тук честотата се
 * извежда от реалните обороти на трансмисията всеки кадър: V10 на R об/мин
 * пали 5 пъти на оборот → основна честота R/60·5 Hz. Смените на предавките
 * дават скока в тона безплатно — оборотите реално падат.
 *
 * Слоеве: два разстроени saw осцилатора на палещата честота + октава надолу
 * (редови пулс) → soft clip → нискочестотен филтър, който се отваря с газта;
 * шум за всмукване/изпускане; тракане на кербовете (AM-модулиран шум);
 * тътен в чакъла; вятър по скоростта.
 *
 * Контекстът се създава при start() — изисква потребителски жест (бутона
 * „Карай"). Никакви асети, нула мрежа.
 */

/** Общо ниво — умишлено умерено; M превключва изцяло. */
const MASTER_VOLUME = 0.32;

/** Палене на цилиндър за V10 четиритактов: 5 изгаряния на оборот. */
const FIRINGS_PER_REV = 5;

/**
 * @returns {{
 *   start: () => void,
 *   stop: () => void,
 *   setMuted: (muted: boolean) => void,
 *   muted: () => boolean,
 *   update: (rpm: number, throttle: number, extras: {kerb: boolean, gravel: boolean, speed: number}) => void,
 *   dispose: () => void,
 * }}
 */
const MUTE_KEY = 'padok-game-muted';

/** Предпочитанието за звука надживява инстанцията (quit → нова обиколка). */
function readMuted() {
    try {
        return localStorage.getItem(MUTE_KEY) === '1';
    } catch {
        return false;
    }
}

function writeMuted(value) {
    try {
        localStorage.setItem(MUTE_KEY, value ? '1' : '0');
    } catch {
        // Блокирано хранилище (private mode) — предпочитанието е само за сесията.
    }
}

export function createEngineSound() {
    let ctx = null;
    let nodes = null;
    let muted = readMuted();
    // Отложеният suspend от stop(): пази се, за да може start() да го отмени.
    // Иначе бърз stop→start (blur→focus, край на реплей) първо резюмира
    // контекста, а закъснелият таймер го суспендва — и звукът "умира".
    let suspendTimer = null;
    // В спряно състояние (реплей/blur/меню) unmute с M НЕ бива да вдига
    // мастера — контекстът е още 'running' до отложения suspend и замразеният
    // двигател би избучал за части от секундата.
    let stopped = true;

    const build = () => {
        ctx = new (window.AudioContext || window.webkitAudioContext)();

        const master = ctx.createGain();
        master.gain.value = 0;

        // Лек компресор връзва слоевете и пази от клипване на редлайн.
        const compressor = ctx.createDynamicsCompressor();
        compressor.threshold.value = -18;
        compressor.ratio.value = 6;
        compressor.connect(master);
        master.connect(ctx.destination);

        // ── Двигател ─────────────────────────────────────────────────────
        const engineMix = ctx.createGain();
        engineMix.gain.value = 0.5;

        const sawA = ctx.createOscillator();
        sawA.type = 'sawtooth';
        const sawB = ctx.createOscillator();
        sawB.type = 'sawtooth';
        sawB.detune.value = 9; // разстройката прави „бръмченето" живо
        const pulse = ctx.createOscillator();
        pulse.type = 'square';
        const pulseGain = ctx.createGain();
        pulseGain.gain.value = 0.35;

        sawA.connect(engineMix);
        sawB.connect(engineMix);
        pulse.connect(pulseGain);
        pulseGain.connect(engineMix);

        // Soft clip — метални хармоници, без цифрова твърдост.
        const shaper = ctx.createWaveShaper();
        const curve = new Float32Array(512);
        for (let i = 0; i < 512; i++) {
            const x = (i / 511) * 2 - 1;
            curve[i] = Math.tanh(2.2 * x);
        }
        shaper.curve = curve;

        const engineFilter = ctx.createBiquadFilter();
        engineFilter.type = 'lowpass';
        engineFilter.Q.value = 1.1;
        engineFilter.frequency.value = 800;

        const engineGain = ctx.createGain();
        engineGain.gain.value = 0;

        engineMix.connect(shaper);
        shaper.connect(engineFilter);
        engineFilter.connect(engineGain);
        engineGain.connect(compressor);

        // ── Шумове (общ буфер бял шум, различни филтри) ──────────────────
        const noiseBuffer = ctx.createBuffer(1, ctx.sampleRate, ctx.sampleRate);
        const data = noiseBuffer.getChannelData(0);
        for (let i = 0; i < data.length; i++) {
            data[i] = Math.random() * 2 - 1;
        }

        const makeNoise = () => {
            const src = ctx.createBufferSource();
            src.buffer = noiseBuffer;
            src.loop = true;
            return src;
        };

        // Всмукване/изпускане — диша с газта.
        const intake = makeNoise();
        const intakeFilter = ctx.createBiquadFilter();
        intakeFilter.type = 'bandpass';
        intakeFilter.frequency.value = 1400;
        intakeFilter.Q.value = 0.8;
        const intakeGain = ctx.createGain();
        intakeGain.gain.value = 0;
        intake.connect(intakeFilter);
        intakeFilter.connect(intakeGain);
        intakeGain.connect(compressor);

        // Кербове: високочестотен шум, амплитудно модулиран ~30 Hz → тракане.
        const kerb = makeNoise();
        const kerbFilter = ctx.createBiquadFilter();
        kerbFilter.type = 'highpass';
        kerbFilter.frequency.value = 500;
        const kerbGain = ctx.createGain();
        kerbGain.gain.value = 0;
        const kerbLfo = ctx.createOscillator();
        kerbLfo.type = 'square';
        kerbLfo.frequency.value = 31;
        const kerbDepth = ctx.createGain();
        kerbDepth.gain.value = 0; // вдига се само на керба
        kerbLfo.connect(kerbDepth);
        kerbDepth.connect(kerbGain.gain);
        kerb.connect(kerbFilter);
        kerbFilter.connect(kerbGain);
        kerbGain.connect(compressor);

        // Чакъл: нисък тътен.
        const gravel = makeNoise();
        const gravelFilter = ctx.createBiquadFilter();
        gravelFilter.type = 'lowpass';
        gravelFilter.frequency.value = 240;
        const gravelGain = ctx.createGain();
        gravelGain.gain.value = 0;
        gravel.connect(gravelFilter);
        gravelFilter.connect(gravelGain);
        gravelGain.connect(compressor);

        // Вятър — расте с квадрата на скоростта.
        const wind = makeNoise();
        const windFilter = ctx.createBiquadFilter();
        windFilter.type = 'bandpass';
        windFilter.frequency.value = 750;
        windFilter.Q.value = 0.4;
        const windGain = ctx.createGain();
        windGain.gain.value = 0;
        wind.connect(windFilter);
        windFilter.connect(windGain);
        windGain.connect(compressor);

        // Свистене на гуми: тесен bandpass с бавно вибрато — вой, не съскане.
        const screech = makeNoise();
        const screechFilter = ctx.createBiquadFilter();
        screechFilter.type = 'bandpass';
        screechFilter.frequency.value = 2600;
        screechFilter.Q.value = 7;
        const screechGain = ctx.createGain();
        screechGain.gain.value = 0;
        const screechLfo = ctx.createOscillator();
        screechLfo.type = 'sine';
        screechLfo.frequency.value = 5.5;
        const screechDepth = ctx.createGain();
        screechDepth.gain.value = 260;
        screechLfo.connect(screechDepth);
        screechDepth.connect(screechFilter.frequency);
        screech.connect(screechFilter);
        screechFilter.connect(screechGain);
        screechGain.connect(compressor);

        // Жагор на трибуните — два разнесени LFO-та по амплитудата.
        const crowd = makeNoise();
        const crowdFilter = ctx.createBiquadFilter();
        crowdFilter.type = 'bandpass';
        crowdFilter.frequency.value = 640;
        crowdFilter.Q.value = 0.6;
        const crowdGain = ctx.createGain();
        crowdGain.gain.value = 0;
        crowd.connect(crowdFilter);
        crowdFilter.connect(crowdGain);
        crowdGain.connect(compressor);

        // Тунелен риверб (Монако): процедурен impulse response, wet само там.
        const convolver = ctx.createConvolver();
        const irLength = Math.floor(ctx.sampleRate * 0.7);
        const ir = ctx.createBuffer(2, irLength, ctx.sampleRate);
        for (let channel = 0; channel < 2; channel++) {
            const channelData = ir.getChannelData(channel);
            let smoothed = 0;
            for (let i = 0; i < irLength; i++) {
                // Затихващ шум, леко изгладен (евтин lowpass) — бетонен тунел.
                const raw = (Math.random() * 2 - 1) * Math.exp((-6 * i) / irLength);
                smoothed = smoothed * 0.6 + raw * 0.4;
                channelData[i] = smoothed;
            }
        }
        convolver.buffer = ir;
        const tunnelSend = ctx.createGain();
        tunnelSend.gain.value = 0;
        engineFilter.connect(tunnelSend);
        tunnelSend.connect(convolver);
        convolver.connect(compressor);

        // Съперник наблизо: втори, приглушен двигател — чува се само когато
        // друга кола е на метри, с панорама според това от коя страна е.
        const rival = ctx.createOscillator();
        rival.type = 'sawtooth';
        const rivalFilter = ctx.createBiquadFilter();
        rivalFilter.type = 'lowpass';
        rivalFilter.frequency.value = 900;
        const rivalGain = ctx.createGain();
        rivalGain.gain.value = 0;
        rival.connect(rivalFilter);
        rivalFilter.connect(rivalGain);
        // StereoPanner липсва в стари WebKit — тогава направо в компресора.
        const rivalPan = ctx.createStereoPanner ? ctx.createStereoPanner() : null;
        if (rivalPan) {
            rivalGain.connect(rivalPan);
            rivalPan.connect(compressor);
        } else {
            rivalGain.connect(compressor);
        }

        for (const src of [sawA, sawB, pulse, kerbLfo, intake, kerb, gravel, wind, rival, screech, screechLfo, crowd]) {
            src.start();
        }

        nodes = {
            master,
            compressor,
            engineGain,
            engineFilter,
            sawA,
            sawB,
            pulse,
            intakeFilter,
            intakeGain,
            kerbGain,
            kerbDepth,
            gravelGain,
            windGain,
            rival,
            rivalGain,
            rivalPan,
            screechGain,
            crowdGain,
            tunnelSend,
            noiseBuffer,
        };
    };

    // Едно-shot помощници: тон и шумов взрив, през master (mute важи).
    const oneShotTone = (type, freq, duration, peak, glideTo = null) => {
        if (!ctx || !nodes || ctx.state !== 'running') {
            return;
        }
        const t = ctx.currentTime;
        const osc = ctx.createOscillator();
        osc.type = type;
        osc.frequency.setValueAtTime(freq, t);
        if (glideTo !== null) {
            osc.frequency.exponentialRampToValueAtTime(Math.max(20, glideTo), t + duration);
        }
        const gain = ctx.createGain();
        gain.gain.setValueAtTime(0.0001, t);
        gain.gain.exponentialRampToValueAtTime(peak, t + 0.012);
        gain.gain.exponentialRampToValueAtTime(0.0001, t + duration);
        osc.connect(gain);
        gain.connect(nodes.compressor);
        osc.start(t);
        osc.stop(t + duration + 0.05);
        osc.onended = () => {
            osc.disconnect();
            gain.disconnect();
        };
    };

    const noiseBurst = (filterFreq, filterType, duration, peak) => {
        if (!ctx || !nodes || ctx.state !== 'running') {
            return;
        }
        const t = ctx.currentTime;
        const src = ctx.createBufferSource();
        // Преизползва общия шумов буфер от build() през нов източник.
        src.buffer = nodes.noiseBuffer;
        const filter = ctx.createBiquadFilter();
        filter.type = filterType;
        filter.frequency.value = filterFreq;
        const gain = ctx.createGain();
        gain.gain.setValueAtTime(peak, t);
        gain.gain.exponentialRampToValueAtTime(0.0001, t + duration);
        src.connect(filter);
        filter.connect(gain);
        gain.connect(nodes.compressor);
        src.start(t);
        src.stop(t + duration + 0.05);
        src.onended = () => {
            src.disconnect();
            filter.disconnect();
            gain.disconnect();
        };
    };

    return {
        start() {
            if (!ctx) {
                try {
                    build();
                } catch {
                    // Без Web Audio (стар браузър/политика) — играта работи тихо.
                    ctx = null;
                    return;
                }
            }
            // Отменя висящ suspend от предишен stop() — виж suspendTimer.
            if (suspendTimer !== null) {
                clearTimeout(suspendTimer);
                suspendTimer = null;
            }
            stopped = false;
            ctx.resume?.();
            if (!muted) {
                nodes.master.gain.setTargetAtTime(MASTER_VOLUME, ctx.currentTime, 0.2);
            }
        },

        stop() {
            stopped = true;
            if (ctx && nodes) {
                nodes.master.gain.setTargetAtTime(0, ctx.currentTime, 0.1);
                // Суспендваме след затихването — паузата/менюто са тихи.
                if (suspendTimer !== null) {
                    clearTimeout(suspendTimer);
                }
                suspendTimer = setTimeout(() => {
                    suspendTimer = null;
                    ctx?.suspend?.();
                }, 300);
            }
        },

        setMuted(value) {
            muted = value;
            writeMuted(value);
            if (ctx && nodes) {
                // Спряно състояние остава тихо и при unmute — предпочитанието
                // е записано, силата идва при следващия start().
                const level = muted || stopped ? 0 : MASTER_VOLUME;
                nodes.master.gain.setTargetAtTime(level, ctx.currentTime, 0.05);
            }
        },

        muted() {
            return muted;
        },

        update(rpm, throttle, extras) {
            if (!ctx || !nodes || ctx.state !== 'running') {
                return;
            }

            const t = ctx.currentTime;
            const firing = Math.max(40, (rpm / 60) * FIRINGS_PER_REV);
            const revRatio = Math.min(1, rpm / 15000);

            // Плавни преходи (20–40 ms) — без стъпаловидно „циклене".
            nodes.sawA.frequency.setTargetAtTime(firing, t, 0.02);
            nodes.sawB.frequency.setTargetAtTime(firing, t, 0.02);
            nodes.pulse.frequency.setTargetAtTime(firing / 2, t, 0.02);

            // Филтърът се отваря с газта и оборотите — „ръмжене" на пълна газ,
            // приглушено пърпорене на подаване.
            const cutoff = 500 + revRatio * 3800 + throttle * 1600;
            nodes.engineFilter.frequency.setTargetAtTime(cutoff, t, 0.04);

            const engineLevel = 0.16 + throttle * 0.4 + revRatio * 0.12;
            nodes.engineGain.gain.setTargetAtTime(engineLevel, t, 0.05);

            nodes.intakeFilter.frequency.setTargetAtTime(900 + revRatio * 2400, t, 0.05);
            nodes.intakeGain.gain.setTargetAtTime(0.02 + throttle * 0.09, t, 0.05);

            // Базата и дълбочината са равни → гейнът се люлее 0..0.32
            // (униполярна AM). Само LFO върху нулева база би обръщал знака на
            // шума — нечуто; истинското „тракане" иска включване/изключване.
            nodes.kerbGain.gain.setTargetAtTime(extras.kerb ? 0.16 : 0, t, 0.015);
            nodes.kerbDepth.gain.setTargetAtTime(extras.kerb ? 0.16 : 0, t, 0.015);
            nodes.gravelGain.gain.setTargetAtTime(extras.gravel ? 0.22 : 0, t, 0.04);

            const windLevel = Math.min(1, (extras.speed / 92) ** 2) * 0.1;
            nodes.windGain.gain.setTargetAtTime(windLevel, t, 0.1);

            // Свистене на гумите: расте с плъзгането; локване (спирачка +
            // започващо плъзгане) също вие. Чуваш лимита ПРЕДИ чакъла.
            const slip = extras.slip ?? 0;
            const slipLevel = Math.max(0, Math.min(1, (slip - 0.22) / 0.5));
            const lockLevel = (extras.brake ?? 0) > 0.7 && slip > 0.12 ? 0.35 : 0;
            const speedFactor = Math.min(1, extras.speed / 22);
            nodes.screechGain.gain.setTargetAtTime(
                Math.min(0.4, (slipLevel * 0.32 + lockLevel) * speedFactor),
                t,
                0.03
            );

            // Тълпата се чува при трибуните на старт-финала.
            nodes.crowdGain.gain.setTargetAtTime((extras.crowd ?? 0) * 0.11, t, 0.2);

            // Тунелът (Монако): мокър send към риверба само вътре.
            nodes.tunnelSend.gain.setTargetAtTime(extras.tunnel ? 0.9 : 0, t, 0.12);
        },

        /**
         * Смяна на предавка: при качване — ignition-cut „крак" (срез на
         * двигателя + метален щрак); при сваляне — кратък blip нагоре.
         *
         * @param {1|-1} direction
         */
        shift(direction) {
            if (!ctx || !nodes || ctx.state !== 'running') {
                return;
            }

            if (direction > 0) {
                const t = ctx.currentTime;
                const current = nodes.engineGain.gain.value;
                nodes.engineGain.gain.cancelScheduledValues(t);
                nodes.engineGain.gain.setValueAtTime(current, t);
                nodes.engineGain.gain.linearRampToValueAtTime(0.02, t + 0.018);
                nodes.engineGain.gain.setTargetAtTime(current, t + 0.07, 0.03);
                noiseBurst(1900, 'bandpass', 0.07, 0.5);
            } else {
                // Auto-blip: къс возходящ рев.
                oneShotTone('sawtooth', 420, 0.12, 0.2, 760);
            }
        },

        /**
         * Бийп на стартовите светлини (по-висок и дълъг при гасенето).
         *
         * @param {number} freq
         * @param {number} [duration]
         */
        beep(freq, duration = 0.09) {
            oneShotTone('square', freq, duration, 0.12);
        },

        /**
         * Удар между коли: тъп нискочестотен взрив + кратко „хлътване" на
         * двигателя, мащабирано по силата.
         *
         * @param {number} strength 0..1
         */
        impact(strength) {
            if (!ctx || !nodes || ctx.state !== 'running') {
                return;
            }
            noiseBurst(230, 'lowpass', 0.16, 0.35 + strength * 0.5);
            oneShotTone('sine', 95, 0.14, 0.3 + strength * 0.3, 48);

            const t = ctx.currentTime;
            const current = nodes.engineGain.gain.value;
            nodes.engineGain.gain.cancelScheduledValues(t);
            nodes.engineGain.gain.setValueAtTime(current * 0.4, t);
            nodes.engineGain.gain.setTargetAtTime(current, t + 0.05, 0.05);
        },

        /**
         * Подиумен джингъл: мажорно арпеджио за П1-П3, неутрално за назад.
         *
         * @param {number} position
         */
        fanfare(position) {
            if (!ctx || !nodes || ctx.state !== 'running') {
                return;
            }
            const notes = position <= 3
                ? [523.25, 659.25, 783.99, 1046.5] // C-E-G-C
                : [440, 554.37]; // кратко и неутрално
            notes.forEach((freq, i) => {
                setTimeout(() => oneShotTone('triangle', freq, 0.32, 0.16), i * 140);
            });
        },

        /** Камбанка за нов личен рекорд: два възходящи чисти тона. */
        recordChime() {
            oneShotTone('sine', 880, 0.22, 0.14);
            setTimeout(() => oneShotTone('sine', 1174.66, 0.3, 0.14), 130);
        },

        /**
         * Най-близкият съперник: сила по разстоянието, тон по скоростта му,
         * панорама според страната, ДОПЛЕР по радиалната скорост — така
         * профучаването наистина профучава. Извън обхват → тишина.
         *
         * @param {number} distance Метри до най-близката друга кола (Infinity = няма)
         * @param {number} speed Нейната скорост, m/s
         * @param {number} pan [-1, 1] — отляво/отдясно на камерата
         * @param {number} [closing] Скорост на сближаване, m/s (+ = приближава)
         */
        updateRival(distance, speed, pan, closing = 0) {
            if (!ctx || !nodes || ctx.state !== 'running') {
                return;
            }

            const t = ctx.currentTime;
            const audible = Number.isFinite(distance) && distance < 70;
            const level = audible ? Math.max(0, 1 - distance / 70) ** 2 * 0.22 : 0;

            // Псевдо-обороти от скоростта; доплерът е преувеличен ×3 —
            // физически точният (±v/340) е почти нечут на тези скорости.
            const rpm = 5000 + Math.min(1, speed / 92) * 9500;
            const doppler = 1 + Math.max(-0.25, Math.min(0.25, (3 * closing) / 340));
            nodes.rival.frequency.setTargetAtTime(
                Math.max(40, (rpm / 60) * FIRINGS_PER_REV * doppler),
                t,
                0.04
            );
            nodes.rivalGain.gain.setTargetAtTime(level, t, 0.06);
            nodes.rivalPan?.pan.setTargetAtTime(Math.max(-1, Math.min(1, pan)), t, 0.06);
        },

        dispose() {
            if (suspendTimer !== null) {
                clearTimeout(suspendTimer);
                suspendTimer = null;
            }
            ctx?.close?.();
            ctx = null;
            nodes = null;
        },
    };
}
