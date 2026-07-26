<?php

declare(strict_types=1);

namespace App\Services\F2\Api;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Клиент за официалните F2 резултати (api.formula1.com, scope /f2/).
 *
 * Ключът се вади по време на работа от HTML-а на fiaformula2.com и се кешира.
 * Причината не е удобство: ключът е константа в билда на сайта им и се сменя
 * при редеплой — зашит в кода означава тихо спиране на неизвестна дата. При
 * 401 кешът се изхвърля и ключът се вади наново веднъж, преди да се предадем.
 *
 * ВНИМАНИЕ при промяна на regex-а: полезният товар в страницата е
 * backslash-escape-нат (`\"public\":\"…\"`). Наивният шаблон `"public":"…"`
 * не хваща нищо.
 *
 * Покритие: само от 2026 г. нататък. По-старите сезони връщат HTTP 200 с
 * празни списъци — не са грешка и не бива да се третират като такава.
 */
class F2ApiClient
{
    private const KEY_CACHE_KEY = 'f2:api-key';

    /** Ключът е 20+ буквено-цифрени знака в escape-нат JSON. */
    private const KEY_PATTERN = '/\\\\"public\\\\":\\\\"([A-Za-z0-9]{20,})\\\\"/';

    /**
     * Календарът на сезона със сесиите и техните начало/край.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws F2ApiException
     */
    public function meetings(int $season): array
    {
        return (array) data_get($this->get('meetings', ['season' => $season]), 'meetings', []);
    }

    /**
     * Класация от свободната тренировка.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws F2ApiException
     */
    public function practice(int $season, int $meetingKey): array
    {
        return $this->results('practice', [
            'season' => $season,
            'meeting' => $meetingKey,
            'session' => 0,
        ]);
    }

    /**
     * Класация от квалификацията.
     *
     * @param  int|null  $sessionNumber  Групи A/B — само за Монако, където
     *                                   квалификацията се дели на q1 и q2.
     * @return array<int, array<string, mixed>>
     *
     * @throws F2ApiException
     */
    public function qualifying(int $season, int $meetingKey, ?int $sessionNumber = null): array
    {
        $query = ['season' => $season, 'meeting' => $meetingKey];

        if ($sessionNumber !== null) {
            $query['session'] = $sessionNumber;
        }

        return $this->results('qualifying', $query);
    }

    /**
     * Класация от състезание: 1 = спринт, 2 = главно.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws F2ApiException
     */
    public function race(int $season, int $meetingKey, int $sessionNumber): array
    {
        return $this->results('race', [
            'season' => $season,
            'meeting' => $meetingKey,
            'session' => $sessionNumber,
        ]);
    }

    /**
     * Класиране при пилотите — авторитетно, с включени бонуси за пол позиция.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws F2ApiException
     */
    public function driverStandings(int $season): array
    {
        return $this->standings('driver-standings-breakdown', $season);
    }

    /**
     * Класиране при отборите.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws F2ApiException
     */
    public function constructorStandings(int $season): array
    {
        return $this->standings('constructor-standings-breakdown', $season);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     *
     * @throws F2ApiException
     */
    private function results(string $endpoint, array $query): array
    {
        return (array) data_get($this->get($endpoint, $query), 'sessionResults.results', []);
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws F2ApiException
     */
    private function standings(string $endpoint, int $season): array
    {
        $data = $this->get($endpoint, ['season' => $season]);

        // Обвивката на класиранията се различава от тази на сесиите и не е
        // документирана никъде — търсим първия списък с championshipPoints,
        // вместо да залагаме на конкретен път.
        return $this->firstListWithKey($data, 'championshipPoints');
    }

    /**
     * @param  mixed  $node
     * @return array<int, array<string, mixed>>
     */
    private function firstListWithKey($node, string $key): array
    {
        if (! is_array($node)) {
            return [];
        }

        foreach ($node as $value) {
            if (is_array($value) && isset($value[0]) && is_array($value[0]) && array_key_exists($key, $value[0])) {
                return $value;
            }

            $nested = $this->firstListWithKey($value, $key);

            if ($nested !== []) {
                return $nested;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws F2ApiException
     */
    private function get(string $endpoint, array $query, bool $keyRefreshed = false): array
    {
        /** @var array{base_url:string, user_agent:string, timeout:int} $config */
        $config = config('services.f2');

        try {
            $response = Http::baseUrl(rtrim($config['base_url'], '/'))
                ->withHeaders([
                    'apikey' => $this->apiKey(),
                    'User-Agent' => $config['user_agent'],
                ])
                ->acceptJson()
                ->timeout($config['timeout'])
                // `when` не е излишно: Laravel хвърля вътрешно, за да задейства
                // повторението, независимо от throw: false. Без филтъра 401
                // също се повтаря и пътят за презареждане на ключа по-долу
                // никога не се стига.
                ->retry(3, 500, $this->shouldRetry(...), throw: false)
                ->get('/'.ltrim($endpoint, '/'), $query);
        } catch (ConnectionException) {
            throw new F2ApiException("Мрежова грешка при връзка с F2 API ({$endpoint}).");
        }

        // 401/403 = ключът е ротирал. Вадим го наново веднъж и повтаряме —
        // но само веднъж, за да не се завъртим безкрайно при истинска забрана.
        if (in_array($response->status(), [401, 403], strict: true) && ! $keyRefreshed) {
            Log::warning("F2 API върна {$response->status()} — презареждам ключа.");
            Cache::forget(self::KEY_CACHE_KEY);

            return $this->get($endpoint, $query, keyRefreshed: true);
        }

        if ($response->failed()) {
            throw new F2ApiException("F2 API върна {$response->status()} за {$endpoint}.");
        }

        return (array) $response->json();
    }

    /**
     * Повтаряме само мрежови грешки и 5xx. 401/403 значи ротирал ключ и се
     * обработва отделно; останалите 4xx повтарянето няма да оправи.
     */
    private function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        return $exception instanceof RequestException
            && $exception->response->serverError();
    }

    /**
     * Публичният ключ от страницата на fiaformula2.com, кеширан.
     *
     * @throws F2ApiException
     */
    private function apiKey(): string
    {
        /** @var array{key_page_url:string, user_agent:string, timeout:int, key_cache_hours:int} $config */
        $config = config('services.f2');

        $cached = Cache::get(self::KEY_CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $url = rtrim($config['key_page_url'], '/').'/'.now()->year;

        try {
            $html = Http::withHeaders(['User-Agent' => $config['user_agent']])
                ->timeout($config['timeout'])
                ->retry(2, 500, throw: false)
                ->get($url)
                ->body();
        } catch (ConnectionException|RequestException) {
            throw new F2ApiException('Мрежова грешка при вадене на ключа от fiaformula2.com.');
        }

        if (preg_match(self::KEY_PATTERN, $html, $matches) !== 1) {
            throw new F2ApiException("Ключът не е намерен в {$url} — вероятно са сменили структурата на страницата.");
        }

        $key = $matches[1];

        Cache::put(self::KEY_CACHE_KEY, $key, now()->addHours($config['key_cache_hours']));

        return $key;
    }
}
