<?php

declare(strict_types=1);

namespace App\Services\LiveTiming;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Управлява OAuth2 (password grant) достъпа до OpenF1. Взима access token през
 * POST /token (form-urlencoded user+pass), кешира го за ~90% от expires_in и
 * го презарежда при изтичане. Защитно: при липса на кредитал или грешка → null.
 */
class OpenF1TokenManager
{
    private const CACHE_KEY = 'openf1:access-token';

    public function hasCredentials(): bool
    {
        return filled(config('services.openf1.username')) && filled(config('services.openf1.password'));
    }

    /**
     * Валиден токен (от кеша или нов). null ако няма кредитали/при грешка.
     */
    public function getToken(): ?string
    {
        if (! $this->hasCredentials()) {
            return null;
        }

        $cached = Cache::get(self::CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->fetchNewToken();
    }

    /**
     * Заявява нов токен и го кешира. null при провал.
     */
    public function fetchNewToken(): ?string
    {
        if (! $this->hasCredentials()) {
            return null;
        }

        try {
            $response = Http::asForm()
                ->timeout((int) config('services.openf1.timeout', 10))
                ->post((string) config('services.openf1.token_url'), [
                    'grant_type' => 'password',
                    'username' => (string) config('services.openf1.username'),
                    'password' => (string) config('services.openf1.password'),
                ]);

            if (! $response->successful()) {
                Log::warning('OpenF1 token заявка неуспешна', ['status' => $response->status()]);

                return null;
            }

            $token = $response->json('access_token');
            $expiresIn = (int) ($response->json('expires_in') ?? 3600);

            if (! is_string($token) || $token === '') {
                return null;
            }

            // Кешираме за ~90% от живота на токена (буфер срещу изтичане).
            $ttl = max(60, (int) floor($expiresIn * 0.9));
            Cache::put(self::CACHE_KEY, $token, now()->addSeconds($ttl));

            return $token;
        } catch (Throwable $e) {
            Log::warning('OpenF1 token заявка хвърли изключение', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Изчиства кеширания токен (напр. след 401 → форсиране на нов).
     */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
