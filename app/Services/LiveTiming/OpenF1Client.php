<?php

declare(strict_types=1);

namespace App\Services\LiveTiming;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Клиент за OpenF1 API (https://openf1.org) — live timing данни за текущата сесия.
 *
 * Защитно кодиране: ВСЯКА заявка е в try/catch, кешира се (rate-limit safety) и
 * при провал връща null/празна колекция + лог — никога не хвърля и не показва
 * остарели данни като пресни.
 */
class OpenF1Client
{
    private string $baseUrl;

    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.openf1.base_url'), '/');
        $this->timeout = (int) config('services.openf1.timeout', 10);
    }

    /**
     * Текущата (последна) сесия. null ако няма или при грешка.
     *
     * @return array{key:int, name:string, type:string, date_start:?Carbon, date_end:?Carbon, meeting_key:?int, circuit_short_name:?string}|null
     */
    public function getCurrentSession(): ?array
    {
        return Cache::remember('openf1:current-session', now()->addSeconds(60), function () {
            $rows = $this->get('sessions', ['session_key' => 'latest']);

            $session = $rows->first();

            if (! is_array($session) || ! isset($session['session_key'])) {
                return null;
            }

            return [
                'key' => (int) $session['session_key'],
                'name' => (string) ($session['session_name'] ?? 'Сесия'),
                'type' => (string) ($session['session_type'] ?? ''),
                'date_start' => $this->parseDate($session['date_start'] ?? null),
                'date_end' => $this->parseDate($session['date_end'] ?? null),
                'meeting_key' => isset($session['meeting_key']) ? (int) $session['meeting_key'] : null,
                'circuit_short_name' => $session['circuit_short_name'] ?? null,
            ];
        });
    }

    /**
     * Пилотите в сесията. Кеш 5 мин (рядко се мени по време на сесия).
     *
     * @return Collection<int, array{driver_number:int, name_acronym:?string, full_name:?string, team_name:?string, team_colour:?string}>
     */
    public function getSessionDrivers(int $sessionKey): Collection
    {
        return Cache::remember("openf1:drivers:{$sessionKey}", now()->addMinutes(5), function () use ($sessionKey) {
            return $this->get('drivers', ['session_key' => $sessionKey])
                ->filter(fn ($d) => is_array($d) && isset($d['driver_number']))
                ->map(fn ($d) => [
                    'driver_number' => (int) $d['driver_number'],
                    'name_acronym' => $d['name_acronym'] ?? null,
                    'full_name' => $d['full_name'] ?? null,
                    'team_name' => $d['team_name'] ?? null,
                    'team_colour' => isset($d['team_colour']) ? '#'.ltrim((string) $d['team_colour'], '#') : null,
                ])
                ->values();
        });
    }

    /**
     * Всички обиколки в сесията. Кеш 5 секунди (rate-limit safety).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getLatestLaps(int $sessionKey): Collection
    {
        return Cache::remember("openf1:laps:{$sessionKey}", now()->addSeconds(5), function () use ($sessionKey) {
            return $this->get('laps', ['session_key' => $sessionKey])
                ->filter(fn ($l) => is_array($l) && isset($l['driver_number']))
                ->values();
        });
    }

    /**
     * Стинтове (гумени състави) по пилот. Кеш 30 секунди.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getStints(int $sessionKey): Collection
    {
        return Cache::remember("openf1:stints:{$sessionKey}", now()->addSeconds(30), function () use ($sessionKey) {
            return $this->get('stints', ['session_key' => $sessionKey])
                ->filter(fn ($s) => is_array($s) && isset($s['driver_number']))
                ->values();
        });
    }

    /**
     * Изпълнява GET заявка защитено. Връща празна колекция при всяка грешка.
     *
     * @param  array<string, mixed>  $query
     * @return Collection<int, mixed>
     */
    private function get(string $endpoint, array $query): Collection
    {
        try {
            $response = Http::acceptJson()
                ->timeout($this->timeout)
                ->get("{$this->baseUrl}/{$endpoint}", $query);

            if (! $response->successful()) {
                Log::warning('OpenF1 заявка неуспешна', ['endpoint' => $endpoint, 'status' => $response->status()]);

                return collect();
            }

            $data = $response->json();

            return is_array($data) ? collect($data) : collect();
        } catch (Throwable $e) {
            Log::warning('OpenF1 заявка хвърли изключение', ['endpoint' => $endpoint, 'error' => $e->getMessage()]);

            return collect();
        }
    }

    private function parseDate(?string $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
