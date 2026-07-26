<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Models\TeamNewsItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Тегли пълния текст на оригиналната статия от external_url — суровина за
 * генератора на пълни статии (реални факти, числа, резултати вместо само
 * 500-знаковия RSS откъс). Best effort: при блокиран/недостъпен източник
 * връща null и генераторът пада обратно на RSS откъса.
 */
class SourceArticleFetcher
{
    private const TIMEOUT_SECONDS = 15;

    private const USER_AGENT = 'PadokNewsBot/1.0 (+https://padok.bg)';

    /**
     * Таван на суровината за prompt-а (~2k токена) — достатъчно за фактите,
     * без да издува input разхода на всяка статия.
     */
    private const MAX_CHARS = 8000;

    /**
     * Под този праг почти сигурно е Cloudflare заглушка/празна страница.
     */
    private const MIN_CHARS = 200;

    public function fetch(TeamNewsItem $item): ?string
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders([
                    'User-Agent' => self::USER_AGENT,
                    'Accept' => 'text/html,application/xhtml+xml',
                ])
                ->get($item->external_url);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        return $this->extractText($response->body());
    }

    /**
     * Наивна екстракция: <article> ако има, минус script/style/навигационния
     * шум. Не е perfect readability parser — LLM-ът толерира остатъчен шум.
     */
    private function extractText(string $html): ?string
    {
        if (preg_match('/<article\b[^>]*>(.*?)<\/article>/is', $html, $matches)) {
            $html = $matches[1];
        }

        $html = (string) preg_replace(
            '/<(script|style|noscript|svg|form|nav|header|footer|aside)\b.*?<\/\1>/is',
            ' ',
            $html,
        );

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = trim((string) preg_replace('/\s*\n\s*/', "\n", $text));

        if (mb_strlen($text) < self::MIN_CHARS) {
            return null;
        }

        return Str::limit($text, self::MAX_CHARS, '');
    }
}
