<?php

declare(strict_types=1);

namespace App\Services\Drivers;

use App\Enums\ResultSessionType;
use App\Models\Driver;
use App\Models\DriverCanonical;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use Illuminate\Support\Facades\DB;

/**
 * Изгражда каноничните пилоти от per-season `drivers` (групирани по slug — един
 * човек = един ред), свързва всеки driver запис чрез canonical_id и изчислява
 * агрегатните статистики. Идемпотентно (updateOrCreate по slug).
 */
class CanonicalDriverBackfiller
{
    /**
     * @return array{canonical:int, linked:int}
     */
    public function backfill(): array
    {
        return DB::transaction(function () {
            $this->createCanonicalsAndLink();
            $this->computeStats();

            return [
                'canonical' => DriverCanonical::query()->count(),
                'linked' => Driver::query()->whereNotNull('canonical_id')->count(),
            ];
        });
    }

    private function createCanonicalsAndLink(): void
    {
        $bySlug = Driver::query()
            ->orderBy('season_id')
            ->get(['id', 'slug', 'driver_code', 'first_name', 'last_name', 'country_code', 'permanent_number', 'photo_url'])
            ->groupBy('slug');

        foreach ($bySlug as $slug => $rows) {
            $latest = $rows->last();          // най-нов сезон (подредени възходящо)
            $byRecency = $rows->reverse();

            $canonical = DriverCanonical::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'code' => $latest->driver_code,
                    'first_name' => $latest->first_name,
                    'last_name' => $latest->last_name,
                    'country_code' => $byRecency->firstWhere(fn (Driver $d) => filled($d->country_code))?->country_code,
                    'permanent_number' => $byRecency->firstWhere(fn (Driver $d) => filled($d->permanent_number))?->permanent_number,
                    'photo_url' => $byRecency->firstWhere(fn (Driver $d) => filled($d->photo_url))?->photo_url,
                ],
            );

            Driver::query()->where('slug', $slug)->update(['canonical_id' => $canonical->id]);
        }
    }

    private function computeStats(): void
    {
        $race = ResultSessionType::Race->value;

        $agg = Result::query()
            ->selectRaw('drivers.canonical_id as cid, '
                ."COUNT(DISTINCT CASE WHEN results.session_type = '{$race}' THEN results.race_id END) as races, "
                ."SUM(CASE WHEN results.position = 1 AND results.session_type = '{$race}' THEN 1 ELSE 0 END) as wins, "
                ."SUM(CASE WHEN results.position BETWEEN 1 AND 3 AND results.session_type = '{$race}' THEN 1 ELSE 0 END) as podiums, "
                .'MIN(races.race_datetime_utc) as first_at, MAX(races.race_datetime_utc) as last_at')
            ->join('drivers', 'drivers.id', '=', 'results.driver_id')
            ->join('races', 'races.id', '=', 'results.race_id')
            ->whereNotNull('drivers.canonical_id')
            ->groupBy('drivers.canonical_id')
            ->get()
            ->keyBy('cid');

        $poles = Race::query()
            ->selectRaw('drivers.canonical_id as cid, COUNT(*) as poles')
            ->join('drivers', 'drivers.id', '=', 'races.pole_driver_id')
            ->whereNotNull('drivers.canonical_id')
            ->groupBy('drivers.canonical_id')
            ->pluck('poles', 'cid');

        $currentSeasonId = Season::query()->where('is_current', true)->value('id');
        $activeIds = $currentSeasonId
            ? Driver::query()->where('season_id', $currentSeasonId)->whereNotNull('canonical_id')
                ->distinct()->pluck('canonical_id')->flip()
            : collect();

        DriverCanonical::query()->chunkById(200, function ($canonicals) use ($agg, $poles, $activeIds) {
            foreach ($canonicals as $canonical) {
                $a = $agg->get($canonical->id);

                $canonical->update([
                    'total_races' => (int) ($a->races ?? 0),
                    'total_wins' => (int) ($a->wins ?? 0),
                    'total_podiums' => (int) ($a->podiums ?? 0),
                    'total_poles' => (int) ($poles[$canonical->id] ?? 0),
                    'first_race_at' => $a->first_at ?? null,
                    'last_race_at' => $a->last_at ?? null,
                    'is_active' => $activeIds->has($canonical->id),
                ]);
            }
        });
    }
}
