<?php

declare(strict_types=1);

namespace App\Services\Homepage;

use App\Enums\ResultSessionType;
use App\Models\Result;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * „Този ден във Формула 1" — исторически състезания, проведени на същия ден и
 * месец (през различни години), заедно с победителя.
 */
class ThisDayInF1Service
{
    /**
     * @return Collection<int, array{year:int, race:string, circuit:?string, circuit_slug:?string, winner:?string, winner_slug:?string, team:?string, color:?string}>
     */
    public function forDate(Carbon $date): Collection
    {
        $month = $date->month;
        $day = $date->day;

        return Cache::remember("this-day-f1:{$month}-{$day}", now()->addDay(), function () use ($month, $day) {
            return Result::query()
                ->where('results.position', 1)
                ->where('results.session_type', ResultSessionType::Race->value)
                ->join('races', 'races.id', '=', 'results.race_id')
                ->whereMonth('races.race_datetime_utc', $month)
                ->whereDay('races.race_datetime_utc', $day)
                ->with(['driver.constructor', 'race.season'])
                ->orderByDesc('races.race_datetime_utc')
                ->limit(8)
                ->get(['results.*'])
                ->map(fn (Result $r) => [
                    'year' => (int) ($r->race?->season?->year ?? $r->race?->race_datetime_utc?->year),
                    'race' => $r->race?->name,
                    'circuit' => $r->race?->circuit,
                    'circuit_slug' => $r->race?->jolpica_id,
                    'winner' => $r->driver?->fullName(),
                    'winner_slug' => $r->driver?->slug,
                    'team' => $r->driver?->constructor?->name,
                    'color' => $r->driver?->constructor?->color_hex,
                ])
                ->values();
        });
    }
}
