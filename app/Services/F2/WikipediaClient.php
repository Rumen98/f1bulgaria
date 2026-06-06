<?php

declare(strict_types=1);

namespace App\Services\F2;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Клиент за Wikipedia API (action=parse, prop=wikitext) за F2 данни.
 *
 * Уважение към Wikipedia: User-Agent, 1 заявка/сек rate limit, агресивен кеш
 * (24h — данните за минали състезания не се менят), timeout + retry. Защитно:
 * при липсваща статия / грешка → null (никога не хвърля).
 */
class WikipediaClient
{
    /** Sentinel за кеширане на липсваща статия (negative cache). */
    private const MISS = '__f2_miss__';

    public function getSeasonPage(int $year): ?string
    {
        return $this->getPage("{$year} Formula 2 Championship");
    }

    public function getRoundPage(int $year, string $location): ?string
    {
        return $this->getPage("{$year} {$location} Formula 2 round");
    }

    /**
     * Връща raw wikitext на статия по точно заглавие. null при
     * липса/грешка/disambiguation. Кеширано: успехи 24h (минали състезания
     * не се менят), липси 6h (бъдещ кръг да се появи щом бъде публикуван).
     */
    public function getPage(string $title): ?string
    {
        $key = 'wikipedia:wikitext:'.md5($title);
        $cached = Cache::get($key);

        if ($cached !== null) {
            return $cached === self::MISS ? null : $cached;
        }

        $wikitext = $this->fetchWikitext($title);

        Cache::put(
            $key,
            $wikitext ?? self::MISS,
            $wikitext !== null ? now()->addDay() : now()->addHours(6),
        );

        return $wikitext;
    }

    private function fetchWikitext(string $title): ?string
    {
        $data = $this->request([
            'action' => 'parse',
            'page' => $title,
            'prop' => 'wikitext',
            'format' => 'json',
            'formatversion' => '2',
            'redirects' => '1',
        ]);

        if ($data === null) {
            return null;
        }

        // Липсваща статия / друга API грешка.
        if (isset($data['error'])) {
            Log::info('Wikipedia статия липсва/грешка', ['title' => $title, 'code' => $data['error']['code'] ?? '?']);

            return null;
        }

        $wikitext = $data['parse']['wikitext'] ?? null;

        if (! is_string($wikitext) || $wikitext === '') {
            return null;
        }

        // Disambiguation страница → не е състезание; пропусни.
        if (str_contains($wikitext, '{{disambiguation') || str_contains($wikitext, '{{Disambiguation')) {
            return null;
        }

        return $wikitext;
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>|null
     */
    public function request(array $query): ?array
    {
        $this->rateLimit();

        try {
            $response = Http::withHeaders(['User-Agent' => (string) config('services.wikipedia.user_agent')])
                ->acceptJson()
                ->timeout((int) config('services.wikipedia.timeout', 15))
                ->retry(2, 500, throw: false)
                ->get((string) config('services.wikipedia.base_url'), $query);

            if ($response->failed()) {
                Log::warning('Wikipedia HTTP грешка', ['status' => $response->status()]);

                return null;
            }

            $json = $response->json();

            return is_array($json) ? $json : null;
        } catch (Throwable $e) {
            Log::warning('Wikipedia заявка хвърли изключение', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function rateLimit(): void
    {
        $ms = (int) config('services.wikipedia.rate_limit_ms', 1000);

        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }
}
