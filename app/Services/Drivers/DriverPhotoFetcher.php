<?php

declare(strict_types=1);

namespace App\Services\Drivers;

use App\Models\Driver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Намира URL към CC-лицензирана снимка на пилот от Wikipedia (Wikimedia Commons)
 * през публичното REST summary API. Връща само URL — не сваляме файла локално
 * (hot-link към Wikimedia CDN). Best-effort: при липса/грешка връща null.
 *
 * @see https://en.wikipedia.org/api/rest_v1/#/Page%20content/get_page_summary__title_
 */
class DriverPhotoFetcher
{
    private const SUMMARY_URL = 'https://en.wikipedia.org/api/rest_v1/page/summary/';

    public function fetch(Driver $driver): ?string
    {
        foreach ($this->candidateTitles($driver) as $title) {
            $url = $this->imageForTitle($title);

            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Заглавия за опит, в ред на предпочитание. Уточнението „(racing driver)"
     * първо — спасява нееднозначни имена (напр. George Russell).
     *
     * @return array<int, string>
     */
    private function candidateTitles(Driver $driver): array
    {
        $name = trim("{$driver->first_name} {$driver->last_name}");

        return array_values(array_unique(array_filter([
            "{$name} (racing driver)",
            $name,
        ])));
    }

    private function imageForTitle(string $title): ?string
    {
        try {
            $response = Http::acceptJson()
                ->timeout(10)
                ->withHeaders(['User-Agent' => 'F1Bulgaria/1.0 (https://f1bulgaria.bg; itcashbroker@gmail.com)'])
                ->get(self::SUMMARY_URL.rawurlencode($title));
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        // Пълен размер, иначе по-малкият thumbnail.
        return $response->json('originalimage.source')
            ?? $response->json('thumbnail.source');
    }
}
