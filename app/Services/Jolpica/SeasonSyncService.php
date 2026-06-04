<?php

declare(strict_types=1);

namespace App\Services\Jolpica;

use App\Enums\SessionType;
use App\Models\Constructor;
use App\Models\Driver;
use App\Models\Race;
use App\Models\Season;
use App\Support\ConstructorColors;
use App\Support\Nationality;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Пълен синхрон на сезон от Jolpica: сезон → конструктори → пилоти → състезания
 * (със сесии). Идемпотентен — повторно изпълнение обновява, не дублира.
 */
class SeasonSyncService
{
    public function __construct(private readonly JolpicaClient $client) {}

    /**
     * @return array{constructors:int, drivers:int, races:int}
     */
    public function sync(int $year): array
    {
        $season = Season::query()->firstOrCreate(['year' => $year]);

        $constructors = $this->syncConstructors($season);
        $drivers = $this->syncDrivers($season, $constructors);
        $races = $this->syncRaces($season);

        return [
            'constructors' => $constructors->count(),
            'drivers' => $drivers->count(),
            'races' => $races,
        ];
    }

    /**
     * @return Collection<string, Constructor> индексирани по jolpica_id
     */
    private function syncConstructors(Season $season): Collection
    {
        $rows = $this->client->constructors($season->year);

        return DB::transaction(function () use ($season, $rows) {
            $byJolpicaId = collect();

            foreach ($rows as $row) {
                $jolpicaId = $row['constructorId'];

                $constructor = Constructor::query()->updateOrCreate(
                    ['season_id' => $season->id, 'jolpica_id' => $jolpicaId],
                    [
                        'name' => $row['name'],
                        'slug' => Str::slug($row['name']),
                        'color_hex' => ConstructorColors::forJolpicaId($jolpicaId),
                    ],
                );

                $byJolpicaId->put($jolpicaId, $constructor);
            }

            return $byJolpicaId;
        });
    }

    /**
     * @param  Collection<string, Constructor>  $constructors
     * @return Collection<int, Driver>
     */
    private function syncDrivers(Season $season, Collection $constructors): Collection
    {
        $rows = $this->client->drivers($season->year);
        $constructorByDriver = $this->mapDriversToConstructors($season->year);

        return DB::transaction(function () use ($season, $rows, $constructors, $constructorByDriver) {
            $drivers = collect();

            foreach ($rows as $row) {
                $jolpicaId = $row['driverId'];
                $constructorJolpicaId = $constructorByDriver[$jolpicaId] ?? null;

                $attributes = [
                    'constructor_id' => $constructorJolpicaId
                        ? $constructors->get($constructorJolpicaId)?->id
                        : null,
                    'first_name' => $row['givenName'],
                    'last_name' => $row['familyName'],
                    'slug' => Str::slug("{$row['givenName']} {$row['familyName']}"),
                    'permanent_number' => isset($row['permanentNumber'])
                        ? (int) $row['permanentNumber']
                        : null,
                    'country_code' => Nationality::toIso3($row['nationality'] ?? null),
                ];

                // Пишем кода само ако Ergast дава такъв — иначе пазим вече зададения
                // (генериран за исторически пилоти), за да не се изтрива при ре-синхрон.
                if (filled($row['code'] ?? null)) {
                    $attributes['driver_code'] = $row['code'];
                }

                $driver = Driver::query()->updateOrCreate(
                    ['season_id' => $season->id, 'jolpica_id' => $jolpicaId],
                    $attributes,
                );

                $drivers->push($driver);
            }

            return $drivers;
        });
    }

    /**
     * Връща карта driverId => constructorId на база класирането на пилотите.
     *
     * @return array<string, string>
     */
    private function mapDriversToConstructors(int $year): array
    {
        $standings = $this->client->driverStandings($year);
        $map = [];

        foreach ($standings as $standing) {
            $driverId = $standing['Driver']['driverId'] ?? null;
            $constructors = $standing['Constructors'] ?? [];
            $lastConstructor = end($constructors);

            if ($driverId && $lastConstructor) {
                $map[$driverId] = $lastConstructor['constructorId'];
            }
        }

        return $map;
    }

    private function syncRaces(Season $season): int
    {
        $rows = $this->client->races($season->year);
        $count = 0;

        DB::transaction(function () use ($season, $rows, &$count) {
            foreach ($rows as $row) {
                $hasSprint = isset($row['Sprint']);

                $race = Race::query()->updateOrCreate(
                    ['season_id' => $season->id, 'round' => (int) $row['round']],
                    [
                        'jolpica_id' => $row['Circuit']['circuitId'] ?? null,
                        'name' => $row['raceName'],
                        'circuit' => $row['Circuit']['circuitName'] ?? '',
                        'country' => $row['Circuit']['Location']['country'] ?? '',
                        'race_datetime_utc' => $this->combineUtc($row['date'] ?? null, $row['time'] ?? null),
                        'qualifying_datetime_utc' => $this->sessionUtc($row, 'Qualifying'),
                        'sprint_datetime_utc' => $this->sessionUtc($row, 'Sprint'),
                        'has_sprint' => $hasSprint,
                    ],
                );

                $this->syncSessions($race, $row);
                $count++;
            }
        });

        return $count;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function syncSessions(Race $race, array $row): void
    {
        $sprintQualiKey = isset($row['SprintQualifying']) ? 'SprintQualifying' : 'SprintShootout';

        $sessions = [
            SessionType::FP1->value => $this->sessionUtc($row, 'FirstPractice'),
            SessionType::FP2->value => $this->sessionUtc($row, 'SecondPractice'),
            SessionType::FP3->value => $this->sessionUtc($row, 'ThirdPractice'),
            SessionType::Qualifying->value => $this->sessionUtc($row, 'Qualifying'),
            SessionType::SprintQuali->value => $this->sessionUtc($row, $sprintQualiKey),
            SessionType::Sprint->value => $this->sessionUtc($row, 'Sprint'),
            SessionType::Race->value => $this->combineUtc($row['date'] ?? null, $row['time'] ?? null),
        ];

        foreach ($sessions as $type => $scheduledAt) {
            if ($scheduledAt === null) {
                continue;
            }

            $race->sessions()->updateOrCreate(
                ['type' => $type],
                ['scheduled_at_utc' => $scheduledAt],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function sessionUtc(array $row, string $key): ?CarbonImmutable
    {
        $session = $row[$key] ?? null;

        if (! is_array($session)) {
            return null;
        }

        return $this->combineUtc($session['date'] ?? null, $session['time'] ?? null);
    }

    private function combineUtc(?string $date, ?string $time): ?CarbonImmutable
    {
        if ($date === null) {
            return null;
        }

        $time = $time !== null ? rtrim($time, 'Z') : '00:00:00';

        return CarbonImmutable::parse("{$date} {$time}", 'UTC');
    }
}
