<?php

declare(strict_types=1);

namespace App\Services\Drivers;

use App\Enums\ResultSessionType;
use App\Models\Driver;
use App\Models\Result;
use Illuminate\Support\Facades\DB;

/**
 * Открива и поправя колизии в driver_code — случаи, в които РАЗЛИЧНИ хора
 * споделят един код (Ergast преизползва 3-буквени кодове между ерите:
 * ROS = Keke/Nico Rosberg, VER = Max/Vergne, MSC = Michael/Mick и т.н.).
 *
 * Кодът остава за пилота с най-много състезателни резултати; останалите
 * получават нов уникален код. Идемпотентно.
 */
class DriverCodeCollisionFixer
{
    public function __construct(private readonly DriverCodeGenerator $generator) {}

    /**
     * @return array{collisions:int, reassigned:int, reassignments:array<int, string>}
     */
    public function fix(): array
    {
        return DB::transaction(function () {
            /** @var array<string, true> $taken */
            $taken = Driver::query()
                ->whereNotNull('driver_code')->where('driver_code', '!=', '')
                ->pluck('driver_code')->unique()
                ->mapWithKeys(fn ($code) => [$code => true])->all();

            $byCode = Driver::query()
                ->whereNotNull('driver_code')->where('driver_code', '!=', '')
                ->get(['id', 'first_name', 'last_name', 'driver_code'])
                ->groupBy('driver_code');

            $stats = ['collisions' => 0, 'reassigned' => 0, 'reassignments' => []];

            foreach ($byCode as $code => $drivers) {
                $persons = $drivers->groupBy(fn (Driver $d) => $this->generator->identityKey($d->first_name, $d->last_name));

                if ($persons->count() <= 1) {
                    continue; // същият човек през сезони — не е колизия
                }

                $stats['collisions']++;

                // Подреждане по брой състезателни резултати — повече = задържа кода.
                $ranked = $persons
                    ->map(fn ($rows) => [
                        'rows' => $rows,
                        'name' => trim($rows->first()->first_name.' '.$rows->first()->last_name),
                        'results' => Result::query()
                            ->whereIn('driver_id', $rows->pluck('id')->all())
                            ->where('session_type', ResultSessionType::Race->value)
                            ->count(),
                    ])
                    ->sortByDesc('results')
                    ->values();

                foreach ($ranked->slice(1) as $person) {
                    $sample = $person['rows']->first();
                    $newCode = $this->generator->uniqueCodeFor($sample->first_name, $sample->last_name, $taken);
                    $taken[$newCode] = true;

                    Driver::query()
                        ->whereIn('id', $person['rows']->pluck('id')->all())
                        ->update(['driver_code' => $newCode]);

                    $stats['reassigned'] += $person['rows']->count();
                    $stats['reassignments'][] = "{$person['name']}: {$code} → {$newCode} ({$person['results']} старта)";
                }
            }

            return $stats;
        });
    }
}
