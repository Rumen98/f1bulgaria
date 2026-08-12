/**
 * Процедурен звук на двигателя (Web Audio) — без записан файл (спестява тегло и
 * заобикаля авторските права върху реални F1 записи).
 *
 * Няколко трионообразни осцилатора на честота ∝ оборотите (доминантата на V6 на
 * 4 такта е rpm/60·3 = честотата на паленето), през lowpass филтър, който се
 * отваря с оборотите. Газта управлява силата. Дава изкачваща се „вой" с характер
 * на двигател, който реагира на оборотите и предавките.
 *
 * Браузърите пускат звук само след потребителски жест — затова `resume()` се
 * вика при първия клавиш/докосване (виж Game).
 */
export class EngineSound {
    constructor() {
        this.ctx = null;
        this.started = false;
    }

    /** Създава/възобновява аудио контекста. Идемпотентен; вика се при жест. */
    resume() {
        if (!this.ctx) {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) {
                return;
            }
            this.ctx = new AudioCtx();
            this.#build();
        }
        if (this.ctx.state === 'suspended') {
            this.ctx.resume();
        }
    }

    #build() {
        const ctx = this.ctx;

        this.master = ctx.createGain();
        this.master.gain.value = 0;
        this.master.connect(ctx.destination);

        this.filter = ctx.createBiquadFilter();
        this.filter.type = 'lowpass';
        this.filter.frequency.value = 1200;
        this.filter.Q.value = 0.7;
        this.filter.connect(this.master);

        // Основна честота + два хармоника за плътност.
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

        this.started = true;
    }

    /**
     * Обновява звука от оборотите и газта. Евтино, вика се на всеки кадър —
     * setTargetAtTime изглажда, за да няма пукане при смяна.
     *
     * @param {number} rpm
     * @param {number} throttle  [0, 1]
     * @param {number} maxRpm
     */
    update(rpm, throttle, maxRpm) {
        if (!this.started || !this.ctx || this.ctx.state !== 'running') {
            return;
        }

        const now = this.ctx.currentTime;
        const rev = Math.min(1, rpm / maxRpm);
        const base = (rpm / 60) * 3; // честота на паленето на V6

        for (const { osc, mult } of this.voices) {
            osc.frequency.setTargetAtTime(base * mult, now, 0.03);
        }

        // Филтърът се отваря с оборотите → по-ярко/остро на високи.
        this.filter.frequency.setTargetAtTime(500 + rev * 6500, now, 0.05);

        // Сила: празен ход + газ + лек принос от оборотите.
        const volume = 0.045 + throttle * 0.14 + rev * 0.05;
        this.master.gain.setTargetAtTime(volume, now, 0.05);
    }

    /** Заглушава плавно (пауза/финал), без да спира контекста. */
    silence() {
        if (this.started && this.ctx) {
            this.master.gain.setTargetAtTime(0, this.ctx.currentTime, 0.1);
        }
    }

    /** Освобождава аудио ресурсите. */
    dispose() {
        if (this.ctx) {
            this.ctx.close().catch(() => {});
            this.ctx = null;
            this.started = false;
        }
    }
}
