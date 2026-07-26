<?php

declare(strict_types=1);

namespace App\Services\News;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Уведомява Bing/Yandex за нови URL-и през IndexNow.
 *
 * При сайт, който публикува на всеки половин час, чакането за обхождане е
 * най-бавната част от индексирането. IndexNow е push вместо pull — безплатен,
 * без API ключ (ключът е произволен стринг, качен като файл в public/).
 *
 * Google не поддържа IndexNow; за него работят sitemap-ите + Search Console.
 */
class IndexNowPinger
{
    private const ENDPOINT = 'https://api.indexnow.org/indexnow';

    private const MAX_URLS = 100;

    /**
     * @param  array<int, string>  $urls
     */
    public function ping(array $urls): bool
    {
        $key = (string) config('services.indexnow.key');

        if ($key === '' || $urls === []) {
            return false;
        }

        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        try {
            $response = Http::timeout(10)->post(self::ENDPOINT, [
                'host' => $host,
                'key' => $key,
                'keyLocation' => rtrim((string) config('app.url'), '/')."/{$key}.txt",
                'urlList' => array_slice(array_values($urls), 0, self::MAX_URLS),
            ]);

            if ($response->failed()) {
                Log::warning("IndexNow върна {$response->status()}.");

                return false;
            }

            return true;
        } catch (Throwable $e) {
            // Уведомяването е best-effort — никога не проваля публикуването.
            Log::warning("IndexNow ping се провали: {$e->getMessage()}");

            return false;
        }
    }
}
