<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Race;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class GameController extends Controller
{
    /**
     * Каталогът на пистите се генерира от `php artisan game:generate-tracks`.
     *
     * Самите точки на трасето НЕ минават през Inertia — те са стотици
     * килобайта и клиентът ги тегли директно от public/, само за избраната
     * писта.
     */
    public function index(): Response
    {
        return Inertia::render('Game/Index', [
            'tracks' => $this->tracks(),
            'weekTrack' => $this->weekTrack(),
        ]);
    }

    /**
     * Пистата на уикенда: най-близкото състезание от календара, чиято писта
     * я има в играта — кара се там, където Ф1 кара този уикенд. До 3 дни след
     * старта неделя вечер още „брои" за уикенда.
     */
    private function weekTrack(): ?string
    {
        return Cache::remember('game.week-track', now()->addHour(), function (): ?string {
            $slugs = array_keys((array) config('game.tracks', []));

            if ($slugs === []) {
                return null;
            }

            /** @var Race|null $race */
            $race = Race::query()
                ->whereIn('circuit', $slugs)
                ->where('race_datetime_utc', '>=', now()->subDays(3))
                ->orderBy('race_datetime_utc')
                ->first();

            return $race?->circuit;
        }) ?: null;
    }

    /**
     * @return array<int, array{slug: string, name: string, location: string, length: float}>
     */
    private function tracks(): array
    {
        return Cache::remember('game.tracks.index', now()->addHour(), function (): array {
            $path = public_path('game-tracks/index.json');

            if (! file_exists($path)) {
                return [];
            }

            try {
                return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }
        });
    }
}
