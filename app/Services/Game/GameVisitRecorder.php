<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Models\GameVisit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Брои кой влиза в играта — и гостите. Без бисквитка: посетителят е дневен
 * хеш (виж visitorKey), така че „уникални" значи „уникални за деня".
 * Записът никога не спира играта: при грешка в базата само логваме.
 */
class GameVisitRecorder
{
    /**
     * Ботове, uptime монитори и preview-та на месинджъри не са хора, които
     * влизат (записът идва от JS, но филтърът е евтин и пази при headless).
     */
    private const NOT_A_VISITOR = '/bot|crawl|spider|slurp|monitor|preview|headless|facebookexternalhit|whatsapp|telegram|curl|wget|python-requests/i';

    /** Таван на ред на посетител и вид за деня — срещу зациклил клиент или скрипт. */
    private const DAILY_CAP = 200;

    public function view(Request $request, string $device): void
    {
        $this->record($request, GameVisit::KIND_VIEW, null, $device);
    }

    public function guestStart(Request $request, string $track, string $device): void
    {
        $this->record($request, GameVisit::KIND_START, $track, $device);
    }

    private function record(Request $request, string $kind, ?string $track, string $device): void
    {
        /** @var User|null $user */
        $user = $request->user();

        // Собственикът тества по цял ден — не е посетител.
        if ($user?->is_admin || preg_match(self::NOT_A_VISITOR, (string) $request->userAgent()) === 1) {
            return;
        }

        try {
            $key = $this->visitorKey($request);
            $today = GameVisit::query()
                ->where('visitor_key', $key)
                ->where('kind', $kind)
                ->where('created_at', '>=', now('Europe/Sofia')->startOfDay()->utc())
                ->count();

            if ($today >= self::DAILY_CAP) {
                return;
            }

            GameVisit::query()->create([
                'visitor_key' => $key,
                'user_id' => $user?->id,
                'kind' => $kind,
                'device' => $device,
                'track_slug' => $track,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Посещението на играта не се записа: '.$e->getMessage());
        }
    }

    /**
     * Дневен хеш: същият човек в същия ден = един ключ; утре — друг. Ключът
     * на приложението прави обръщането по речник безсмислено. Денят е
     * софийски — както прозорците в админа, иначе полунощ UTC (03:00 у нас)
     * би броила един човек два пъти в „днес".
     */
    public function visitorKey(Request $request): string
    {
        return hash('sha256', implode('|', [
            (string) config('app.key'),
            now('Europe/Sofia')->toDateString(),
            (string) $request->ip(),
            (string) $request->userAgent(),
        ]));
    }
}
