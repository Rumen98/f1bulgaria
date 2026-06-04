<?php

declare(strict_types=1);

namespace App\Services\Drivers;

use App\Models\Driver;
use App\Support\Nationality;
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
     * Заглавия за опит, в ред на предпочитание:
     *  1. „(racing driver)" — спасява нееднозначни имена (напр. George Russell)
     *  2. чисто име — работи за повечето
     *  3. „… Jr." — за синове на пилоти (напр. Carlos Sainz Jr.)
     *  4. „({демоним} racing driver)" — допълнителна дисамбигуация по националност
     *
     * @return array<int, string>
     */
    private function candidateTitles(Driver $driver): array
    {
        $name = trim("{$driver->first_name} {$driver->last_name}");
        $demonym = Nationality::demonym($driver->country_code);

        return array_values(array_unique(array_filter([
            "{$name} (racing driver)",
            $name,
            "{$name} Jr.",
            $demonym !== null ? "{$name} ({$demonym} racing driver)" : null,
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

        // Дисамбигуация (напр. „Carlos Sainz" → баща и син) → пропусни и опитай
        // следващото заглавие, вместо да върнем грешна/липсваща снимка.
        if ($response->json('type') === 'disambiguation') {
            return null;
        }

        // Пълен размер, иначе по-малкият thumbnail.
        return $response->json('originalimage.source')
            ?? $response->json('thumbnail.source');
    }
}
