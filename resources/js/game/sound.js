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

        for (const src of [sawA, sawB, pulse, kerbLfo, intake, kerb, gravel, wind]) {
            src.start();
        }

        nodes = {
            master,
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
            ctx.resume?.();
            if (!muted) {
                nodes.master.gain.setTargetAtTime(MASTER_VOLUME, ctx.currentTime, 0.2);
            }
        },

        stop() {
            if (ctx && nodes) {
                nodes.master.gain.setTargetAtTime(0, ctx.currentTime, 0.1);
                // Суспендваме след затихването — паузата/менюто са тихи.
                setTimeout(() => ctx?.suspend?.(), 300);
            }
        },

        setMuted(value) {
            muted = value;
            writeMuted(value);
            if (ctx && nodes) {
                nodes.master.gain.setTargetAtTime(muted ? 0 : MASTER_VOLUME, ctx.currentTime, 0.05);
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
        },

        dispose() {
            ctx?.close?.();
            ctx = null;
            nodes = null;
        },
    };
}
