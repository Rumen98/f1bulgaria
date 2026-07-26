<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\F2Driver;
use App\Models\F2Result;
use App\Models\F2Season;
use App\Support\DriverName;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class TsolovController extends Controller
{
    /** Slug на Цолов в `f2_drivers` — по него намираме реда му в сезона. */
    private const TSOLOV_SLUG = 'nikola-tsolov';

    public function index(): Response
    {
        return Inertia::render('Tsolov', [
            'profile' => $this->profile(),
        ]);
    }

    /**
     * Редакционното съдържание от config/tsolov.php, припокрито с живите числа
     * от базата.
     *
     * Файлът се чете ДИРЕКТНО, а не през config() — кешираният config
     * (bootstrap/cache/config.php) иначе сервира старите стойности до следващо
     * `php artisan config:cache`. Така редакция + deploy е достатъчно.
     *
     * Класирането и статистиката вече идват от `f2:sync` (официалния F2 API),
     * а не се поддържат на ръка: страницата се обновяваше по веднъж на кръг
     * и между кръговете показваше остарял аванс в шампионата.
     *
     * @return array<string, mixed>
     */
    private function profile(): array
    {
        /** @var array<string, mixed> $profile */
        $profile = require config_path('tsolov.php');

        $live = $this->liveSeason();

        // Без синхронизиран сезон остават стойностите от файла — по-добре
        // леко остаряло, отколкото празна страница.
        return $live === null ? $profile : [...$profile, ...$live];
    }

    /**
     * @return array{season_stats:array<string, mixed>, standings:array<int, array<string, mixed>>, standings_note:string}|null
     */
    private function liveSeason(): ?array
    {
        $season = F2Season::query()->where('is_current', true)->first();

        if ($season === null) {
            return null;
        }

        $top = F2Driver::query()
            ->where('f2_season_id', $season->id)
            ->whereNotNull('position')
            ->with('team')
            ->orderBy('position')
            ->limit(5)
            ->get();

        if ($top->isEmpty()) {
            return null;
        }

        $tsolov = F2Driver::query()
            ->where('f2_season_id', $season->id)
            ->where('slug', self::TSOLOV_SLUG)
            ->first();

        if ($tsolov === null || $tsolov->position === null) {
            return null;
        }

        $second = $top->firstWhere('position', 2);
        $rounds = $season->races()->whereHas('sessions.results')->count();

        return [
            'season_stats' => [
                'wins' => $this->wins($tsolov),
                'points' => (float) $tsolov->points,
                'championship_position' => $tsolov->position,
                // Аванс само когато води; иначе числото е безсмислено.
                'championship_lead' => $tsolov->position === 1 && $second !== null
                    ? (float) $tsolov->points - (float) $second->points
                    : null,
            ],
            'standings' => $this->standings($top),
            'standings_note' => $rounds > 0
                ? "Класиране след {$rounds} от ".$season->races()->count().' кръга · fiaformula2.com'
                : 'Класиране · fiaformula2.com',
        ];
    }

    /**
     * @param  Collection<int, F2Driver>  $top
     * @return array<int, array<string, mixed>>
     */
    private function standings(Collection $top): array
    {
        return $top->map(fn (F2Driver $driver): array => [
            'pos' => $driver->position,
            'code' => $driver->tla ?? mb_substr($driver->first_name, 0, 1).mb_substr($driver->last_name, 0, 1),
            'name' => DriverName::display($driver->slug, $driver->fullName()),
            'team' => $driver->team?->name,
            'points' => (float) $driver->points,
            'is_tsolov' => $driver->slug === self::TSOLOV_SLUG,
        ])->all();
    }

    /**
     * Победи в състезание през сезона. Тренировките и квалификациите не се
     * броят — първо място там не е победа.
     */
    private function wins(F2Driver $driver): int
    {
        return F2Result::query()
            ->where('f2_driver_id', $driver->id)
            ->where('position', 1)
            ->whereHas('session', fn ($query) => $query->whereIn('session_type', ['sprint_race', 'feature_race']))
            ->count();
    }
}
