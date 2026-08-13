/**
 * Звук на двигателя (Web Audio).
 *
 * Основен режим: реален семпъл (онборд V10), пуснат в цикъл, чиято скорост на
 * възпроизвеждане (playbackRate) следва оборотите — така семплът „върти" с
 * двигателя: изкачва се при газ/ускорение и пада при смяна нагоре. Силата следва
 * газта. Ако семплът не се зареди/декодира — минаваме на процедурен синтез, за
 * да има звук навсякъде.
 *
 * Браузърите пускат звук само след потребителски жест — затова `resume()` се
 * вика при първия клавиш/докосване (виж Game).
 */
export class EngineSound {
    /**
     * @param {string} sampleUrl
     */
    constructor(sampleUrl = '/game-textures/car-sound/engine.mp3') {
        this.sampleUrl = sampleUrl;
        this.ctx = null;
        this.ready = false;
        this.usingSample = false;
    }

    /** Създава/възобновява аудио контекста. Идемпотентен; вика се при жест. */
    resume() {
        if (!this.ctx) {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) {
                return;
            }
            this.ctx = new AudioCtx();
            this.#init();
        }
        if (this.ctx.state === 'suspended') {
            this.ctx.resume();
        }
    }

    async #init() {
        const ctx = this.ctx;

        this.master = ctx.createGain();
        this.master.gain.value = 0;
        this.master.connect(ctx.destination);

        try {
            const response = await fetch(this.sampleUrl);
            if (!response.ok) {
                throw new Error(`sample ${response.status}`);
            }
            const encoded = await response.arrayBuffer();
            const buffer = await ctx.decodeAudioData(encoded);
            if (!this.ctx) {
                return; // освободен по време на декодиране
            }
            this.#startSample(buffer);
            this.usingSample = true;
        } catch {
            if (this.ctx) {
                this.#startProcedural();
                this.usingSample = false;
            }
        }

        this.ready = true;
    }

    #startSample(buffer) {
        const ctx = this.ctx;

        // Лек lowpass омекотява mp3 артефактите на високите playbackRate-ове.
        this.filter = ctx.createBiquadFilter();
        this.filter.type = 'lowpass';
        this.filter.frequency.value = 9000;
        this.filter.connect(this.master);

        this.source = ctx.createBufferSource();
        this.source.buffer = buffer;
        this.source.loop = true;
        this.source.connect(this.filter);
        this.source.start();
    }

    #startProcedural() {
        const ctx = this.ctx;

        this.filter = ctx.createBiquadFilter();
        this.filter.type = 'lowpass';
        this.filter.frequency.value = 1200;
        this.filter.Q.value = 0.7;
        this.filter.connect(this.master);

        this.voices = [];
        for (const [mult, gain, type] of [[1, 0.5, 'sawtooth'], [2, 0.3, 'sawtooth'], [3, 0.18, 'square']]) {
            const osc = ctx.createOscillator();
            osc.type = type;
            const gainNode = ctx.createGain();
            gainNode.gain.value = gain;
            osc.connect(gainNode);
            gainNode.connect(this.filter);
            osc.start();
            this.voices.push({ osc, mult });
        }
    }

    /**
     * Обновява звука от оборотите и газта. Евтино, вика се на всеки кадър —
     * setTargetAtTime изглажда, за да няма пукане.
     *
     * @param {number} rpm
     * @param {number} throttle  [0, 1]
     * @param {number} maxRpm
     */
    update(rpm, throttle, maxRpm) {
        if (!this.ready || !this.ctx || this.ctx.state !== 'running') {
            return;
        }

        const now = this.ctx.currentTime;
        const rev = Math.min(1, rpm / maxRpm);

        if (this.usingSample) {
            // Скоростта на семпъла следва оборотите → „върти" с двигателя;
            // смяната нагоре (спад на оборотите) сваля тона осезаемо.
            this.source.playbackRate.setTargetAtTime(0.7 + rev * 0.85, now, 0.04);
            const volume = 0.14 + throttle * 0.5 + rev * 0.2;
            this.master.gain.setTargetAtTime(Math.min(1, volume), now, 0.06);
            return;
        }

        // Процедурен fallback: честота ∝ обороти (V6 firing), филтър + сила.
        const base = (rpm / 60) * 3;
        for (const { osc, mult } of this.voices) {
            osc.frequency.setTargetAtTime(base * mult, now, 0.03);
        }
        this.filter.frequency.setTargetAtTime(500 + rev * 6500, now, 0.05);
        this.master.gain.setTargetAtTime(0.045 + throttle * 0.14 + rev * 0.05, now, 0.05);
    }

    /** Заглушава плавно (пауза/финал), без да спира контекста. */
    silence() {
        if (this.ready && this.ctx) {
            this.master.gain.setTargetAtTime(0, this.ctx.currentTime, 0.1);
        }
    }

    /** Освобождава аудио ресурсите. */
    dispose() {
        if (this.ctx) {
            this.ctx.close().catch(() => {});
            this.ctx = null;
            this.ready = false;
        }
    }
}
