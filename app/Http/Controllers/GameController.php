<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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
        ]);
    }

    /**
     * @return array<int, array{slug: string, name: string, location: string, length: float}>
     */
    private function tracks(): array
    {
        return Cache::remember('game.tracks.index', now()->addHour(), function (): array {
            $path = public_path('game/tracks/index.json');

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
