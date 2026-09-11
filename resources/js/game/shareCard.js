/**
 * Share-карта за Telegram: 2D canvas 1200×630 с времето от обиколката.
 *
 * Нарочно НЕ снимка на WebGL платното — рендерерът е без
 * preserveDrawingBuffer и toDataURL извън кадъра връща черно. Чистата карта
 * е и по-четлива в чата, и носи линк за дуел срещу твоя дух.
 *
 * Зарежда се мързеливо (dynamic import) — трябва само при натиснат „Сподели".
 */

const WIDTH = 1200;
const HEIGHT = 630;

const STATE_COLORS = {
    purple: '#e879f9',
    green: '#34d399',
    yellow: '#fbbf24',
    none: '#ffffff',
};

/*
 * Exo 2 е display шрифтът на сайта (tailwind.config.js `font-display`); в
 * canvas няма CSS fallback — ако семейството не е заредено, браузърът
 * рисува със системния шрифт. Затова го чакаме изрично през Font Loading
 * API; при липса на API/шрифт (стар браузър, блокиран CDN) картата пак
 * се рисува, само с fallback-а.
 */
const DISPLAY_FAMILY = '"Exo 2", Figtree, system-ui, -apple-system, sans-serif';
const TEXT_FAMILY = 'Figtree, system-ui, -apple-system, sans-serif';

/**
 * @param {object} data
 * @param {string} data.trackName
 * @param {number} data.lapMs
 * @param {Array<number|null>} data.sectorsMs
 * @param {number|null} data.rank
 * @param {'purple'|'green'|'yellow'|'none'} data.state
 * @param {string|null} data.challengeUrl Линк за дуел срещу духа на играча
 * @param {Array<[number, number]>|null} [data.outline] Очертанието на пистата
 *        (нормализирани 0..1 точки от Game.minimap.path) — воден знак
 * @returns {Promise<Blob>}
 */
export async function buildShareCard(data) {
    await loadDisplayFont();

    const canvas = document.createElement('canvas');
    canvas.width = WIDTH;
    canvas.height = HEIGHT;
    const ctx = canvas.getContext('2d');

    // Фон: тъмен градиент + карирана лента отгоре.
    const gradient = ctx.createLinearGradient(0, 0, WIDTH, HEIGHT);
    gradient.addColorStop(0, '#101014');
    gradient.addColorStop(1, '#1b1b22');
    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, WIDTH, HEIGHT);

    const square = 20;
    for (let x = 0; x < WIDTH / square; x++) {
        for (let y = 0; y < 2; y++) {
            ctx.fillStyle = (x + y) % 2 === 0 ? '#fafafa' : '#0a0a0a';
            ctx.fillRect(x * square, y * square, square, square);
        }
    }

    // Воден знак: очертанието на пистата вдясно, под текста.
    if (Array.isArray(data.outline) && data.outline.length > 2) {
        drawOutline(ctx, data.outline);
    }

    // Брандът: „Падок" + червената точка (никакво „F1" — trademark).
    ctx.fillStyle = '#ffffff';
    ctx.font = `900 64px ${DISPLAY_FAMILY}`;
    ctx.textBaseline = 'top';
    ctx.fillText('Падок', 70, 90);
    ctx.fillStyle = '#e10600';
    const brandWidth = ctx.measureText('Падок').width;
    ctx.beginPath();
    ctx.arc(70 + brandWidth + 18, 90 + 52, 9, 0, Math.PI * 2);
    ctx.fill();

    // Пистата.
    ctx.fillStyle = '#a1a1aa';
    ctx.font = `600 34px ${TEXT_FAMILY}`;
    ctx.fillText(data.trackName, 70, 200);

    // Времето — голямо, в цвета на постижението, с брандовата лента отляво
    // като в ТВ графиката.
    ctx.fillStyle = '#e10600';
    ctx.fillRect(62, 262, 8, 130);
    ctx.fillStyle = STATE_COLORS[data.state] ?? '#ffffff';
    ctx.font = `900 150px ${DISPLAY_FAMILY}`;
    ctx.fillText(formatLap(data.lapMs), 90, 250);

    // Секторите.
    ctx.font = `700 30px ${DISPLAY_FAMILY}`;
    let sectorX = 90;
    (data.sectorsMs ?? []).forEach((sector, i) => {
        ctx.fillStyle = '#71717a';
        ctx.fillText(`S${i + 1}`, sectorX, 440);
        ctx.fillStyle = '#d4d4d8';
        const value = sector === null ? '—' : (sector / 1000).toFixed(3);
        ctx.fillText(value, sectorX + 50, 440);
        sectorX += 230;
    });

    // Позицията в класацията.
    if (data.rank !== null && data.rank !== undefined) {
        const label = `Позиция #${data.rank}`;
        ctx.font = `700 34px ${DISPLAY_FAMILY}`;
        const width = ctx.measureText(label).width;
        ctx.fillStyle = data.rank === 1 ? 'rgba(245,193,78,0.18)' : 'rgba(255,255,255,0.08)';
        roundRect(ctx, WIDTH - width - 140, 96, width + 60, 62, 16);
        ctx.fill();
        ctx.fillStyle = data.rank === 1 ? '#f5c14e' : '#e4e4e7';
        ctx.fillText(label, WIDTH - width - 110, 108);
    }

    // Линкът за дуел.
    ctx.fillStyle = '#71717a';
    ctx.font = `500 26px ${TEXT_FAMILY}`;
    ctx.fillText(
        data.challengeUrl ? `👻 Дуел срещу мен: ${data.challengeUrl}` : 'padok.bg/game',
        70,
        545
    );

    return new Promise((resolve, reject) => {
        canvas.toBlob((blob) => {
            if (blob) {
                resolve(blob);
            } else {
                reject(new Error('canvas.toBlob върна null'));
            }
        }, 'image/png');
    });
}

/**
 * Чака Exo 2 в теглата, които картата ползва. Никога не хвърля — липсващ
 * шрифт е козметика, не причина „Сподели" да се провали.
 */
async function loadDisplayFont() {
    if (typeof document === 'undefined' || !document.fonts?.load) {
        return;
    }
    try {
        await Promise.all([
            document.fonts.load(`900 150px ${DISPLAY_FAMILY}`),
            document.fonts.load(`700 34px ${DISPLAY_FAMILY}`),
        ]);
    } catch {
        // Блокиран CDN/offline — рисуваме със системния шрифт.
    }
}

/**
 * Очертанието на пистата като едва видим воден знак в дясната половина.
 * Точките са квадратно нормализирани (0..1), затова само се мащабират.
 *
 * @param {CanvasRenderingContext2D} ctx
 * @param {Array<[number, number]>} outline
 */
function drawOutline(ctx, outline) {
    const size = 400;
    const left = WIDTH - size - 60;
    const top = HEIGHT - size - 40;

    ctx.save();
    ctx.beginPath();
    for (let i = 0; i < outline.length; i++) {
        const [x, y] = outline[i];
        const px = left + x * size;
        const py = top + y * size;
        if (i === 0) {
            ctx.moveTo(px, py);
        } else {
            ctx.lineTo(px, py);
        }
    }
    ctx.closePath();
    ctx.lineJoin = 'round';
    ctx.lineWidth = 14;
    ctx.strokeStyle = 'rgba(255,255,255,0.07)';
    ctx.stroke();
    ctx.lineWidth = 3;
    ctx.strokeStyle = 'rgba(225,6,0,0.35)';
    ctx.stroke();
    ctx.restore();
}

function formatLap(ms) {
    const minutes = Math.floor(ms / 60000);
    const seconds = ((ms % 60000) / 1000).toFixed(3).padStart(6, '0');
    return `${minutes}:${seconds}`;
}

function roundRect(ctx, x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
}
