<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\F2SessionType;
use App\Models\F2Race;
use App\Models\F2RaceSession;
use App\Support\CountryFlag;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class F2RaceController extends Controller
{
    public function show(string $race, string $session): Response
    {
        $raceModel = F2Race::query()->where('slug', $race)->with('season')->firstOrFail();

        $type = $session === 'sprint' ? F2SessionType::SprintRace : F2SessionType::FeatureRace;

        $sessionModel = F2RaceSession::query()
            ->where('f2_race_id', $raceModel->id)
            ->where('session_type', $type->value)
            ->with(['results.driver.team', 'poleDriver', 'fastestLapDriver'])
            ->first();

        abort_if($sessionModel === null, 404);

        // Кои сесии съществуват за този кръг (за toggle-а).
        $available = F2RaceSession::query()->where('f2_race_id', $raceModel->id)
            ->pluck('session_type')->map(fn ($t) => $t->value)->all();

        return Inertia::render('F2/RaceShow', [
            'race' => [
                'slug' => $raceModel->slug,
                'location' => $raceModel->location_name,
                'round' => $raceModel->round,
                'season' => $raceModel->season?->year,
                'circuit_jolpica_id' => $raceModel->circuit_jolpica_id,
            ],
            'sessionType' => $session,
            'sessionLabel' => $type->label(),
            'hasSprint' => in_array(F2SessionType::SprintRace->value, $available, true),
            'hasFeature' => in_array(F2SessionType::FeatureRace->value, $available, true),
            'pole' => $sessionModel->poleDriver ? $this->driverRef($sessionModel->poleDriver) : null,
            'fastestLap' => $sessionModel->fastestLapDriver ? [
                ...$this->driverRef($sessionModel->fastestLapDriver),
                'time' => $sessionModel->fastest_lap_time,
            ] : null,
            'results' => $this->results($sessionModel),
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function results(F2RaceSession $session): Collection
    {
        return $session->results
            ->sortBy(fn ($r) => $r->position ?? 999)
            ->values()
            ->map(fn ($r) => [
                'position' => $r->position,
                'status' => $r->status,
                'car_number' => $r->driver?->car_number,
                'driver' => $r->driver?->fullName(),
                'slug' => $r->driver?->slug,
                'flag' => CountryFlag::emoji($r->driver?->country_code),
                'is_bulgarian' => $r->driver?->country_code === 'BUL',
                'team' => $r->driver?->team?->name,
                'grid' => $r->grid_position,
                'laps' => $r->laps_completed,
                'time_or_gap' => $r->time_or_gap,
                'points' => (float) $r->points,
                'fastest_lap' => $r->fastest_lap,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function driverRef($driver): array
    {
        return [
            'driver' => $driver->fullName(),
            'slug' => $driver->slug,
            'flag' => CountryFlag::emoji($driver->country_code),
        ];
    }
}
