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

/**
 * @param {object} data
 * @param {string} data.trackName
 * @param {number} data.lapMs
 * @param {Array<number|null>} data.sectorsMs
 * @param {number|null} data.rank
 * @param {'purple'|'green'|'yellow'|'none'} data.state
 * @param {string|null} data.challengeUrl Линк за дуел срещу духа на играча
 * @returns {Promise<Blob>}
 */
export async function buildShareCard(data) {
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

    // Брандът: „Падок" + червената точка (никакво „F1" — trademark).
    ctx.fillStyle = '#ffffff';
    ctx.font = '900 64px system-ui, -apple-system, sans-serif';
    ctx.textBaseline = 'top';
    ctx.fillText('Падок', 70, 90);
    ctx.fillStyle = '#e10600';
    const brandWidth = ctx.measureText('Падок').width;
    ctx.beginPath();
    ctx.arc(70 + brandWidth + 18, 90 + 52, 9, 0, Math.PI * 2);
    ctx.fill();

    // Пистата.
    ctx.fillStyle = '#a1a1aa';
    ctx.font = '600 34px system-ui, -apple-system, sans-serif';
    ctx.fillText(data.trackName, 70, 200);

    // Времето — голямо, в цвета на постижението.
    ctx.fillStyle = STATE_COLORS[data.state] ?? '#ffffff';
    ctx.font = '900 150px ui-monospace, SFMono-Regular, Consolas, monospace';
    ctx.fillText(formatLap(data.lapMs), 62, 250);

    // Секторите.
    ctx.font = '600 30px ui-monospace, SFMono-Regular, Consolas, monospace';
    let sectorX = 70;
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
        ctx.font = '700 34px system-ui, -apple-system, sans-serif';
        const width = ctx.measureText(label).width;
        ctx.fillStyle = data.rank === 1 ? 'rgba(245,193,78,0.18)' : 'rgba(255,255,255,0.08)';
        roundRect(ctx, WIDTH - width - 140, 96, width + 60, 62, 16);
        ctx.fill();
        ctx.fillStyle = data.rank === 1 ? '#f5c14e' : '#e4e4e7';
        ctx.fillText(label, WIDTH - width - 110, 108);
    }

    // Линкът за дуел.
    ctx.fillStyle = '#71717a';
    ctx.font = '500 26px system-ui, -apple-system, sans-serif';
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
