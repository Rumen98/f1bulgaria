<?php

declare(strict_types=1);

namespace App\Services\Jolpica;

use App\Models\Constructor;
use App\Models\Driver;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use App\Services\Predictions\PredictionScoringService;
use App\Support\Nationality;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Синхрон на резултатите от едно състезание + определяне на pole от
 * квалификацията. След успешен синхрон задейства точкуването на прогнозите.
 */
class ResultSyncService
{
    public function __construct(
        private readonly JolpicaClient $client,
        private readonly PredictionScoringService $scoring,
    ) {}

    /**
     * @return array{results:int, scored:int}
     */
    public function sync(Race $race): array
    {
        $season = $race->season;
        $year = $season->year;
        $round = $race->round;

        $rows = $this->client->results($year, $round);

        $synced = DB::transaction(function () use ($race, $season, $rows) {
            $count = 0;

            foreach ($rows as $row) {
                $driver = $this->resolveDriver($season, $row['Driver'], $row['Constructor'] ?? null);

                Result::query()->updateOrCreate(
                    ['race_id' => $race->id, 'driver_id' => $driver->id],
                    [
                        'jolpica_id' => $row['Driver']['driverId'].'@'.$race->round,
                        'position' => $this->parsePosition($row['positionText'] ?? null),
                        'points' => (float) ($row['points'] ?? 0),
                        'dnf' => $this->isDnf($row['status'] ?? ''),
                        'fastest_lap' => ($row['FastestLap']['rank'] ?? null) === '1',
                        'grid_position' => isset($row['grid']) ? (int) $row['grid'] : null,
                    ],
                );

                $count++;
            }

            $this->syncPole($race, $season);

            return $count;
        });

        $scored = 0;

        if ($synced > 0) {
            $scored = $this->scoring->scoreRace($race->fresh());
        }

        return ['results' => $synced, 'scored' => $scored];
    }

    private function syncPole(Race $race, Season $season): void
    {
        $qualifying = $this->client->qualifying($season->year, $race->round);

        foreach ($qualifying as $row) {
            if (($row['position'] ?? null) === '1') {
                $poleDriver = $this->resolveDriver($season, $row['Driver'], $row['Constructor'] ?? null);
                $race->update(['pole_driver_id' => $poleDriver->id]);

                return;
            }
        }
    }

    /**
     * Намира пилота за сезона по jolpica_id, а ако липсва — го създава от
     * данните в резултата (напр. резервен пилот, нает извън стартовия списък).
     *
     * @param  array<string, mixed>  $driverData
     * @param  array<string, mixed>|null  $constructorData
     */
    private function resolveDriver(Season $season, array $driverData, ?array $constructorData): Driver
    {
        $constructorId = null;

        if ($constructorData !== null) {
            $constructor = Constructor::query()->updateOrCreate(
                ['season_id' => $season->id, 'jolpica_id' => $constructorData['constructorId']],
                [
                    'name' => $constructorData['name'] ?? $constructorData['constructorId'],
                    'slug' => Str::slug($constructorData['name'] ?? $constructorData['constructorId']),
                ],
            );
            $constructorId = $constructor->id;
        }

        return Driver::query()->updateOrCreate(
            ['season_id' => $season->id, 'jolpica_id' => $driverData['driverId']],
            [
                'constructor_id' => $constructorId,
                'driver_code' => $driverData['code'] ?? null,
                'first_name' => $driverData['givenName'],
                'last_name' => $driverData['familyName'],
                'slug' => Str::slug("{$driverData['givenName']} {$driverData['familyName']}"),
                'permanent_number' => isset($driverData['permanentNumber'])
                    ? (int) $driverData['permanentNumber']
                    : null,
                'country_code' => Nationality::toIso3($driverData['nationality'] ?? null),
            ],
        );
    }

    private function parsePosition(?string $positionText): ?int
    {
        // Числова позиция = класиран. "R", "D", "W", "N", "F" → null.
        return is_numeric($positionText) ? (int) $positionText : null;
    }

    private function isDnf(string $status): bool
    {
        // Класиран = "Finished" или "+N Lap(s)". Всичко друго (Accident, Engine,
        // Retired, Disqualified, ...) се брои за DNF.
        if ($status === 'Finished') {
            return false;
        }

        return ! Str::contains($status, 'Lap');
    }
}
