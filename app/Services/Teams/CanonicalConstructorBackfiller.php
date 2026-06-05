<?php

declare(strict_types=1);

namespace App\Services\Teams;

use App\Enums\ResultSessionType;
use App\Models\Constructor;
use App\Models\ConstructorCanonical;
use App\Models\Result;
use App\Models\Season;
use Illuminate\Support\Facades\DB;

/**
 * Изгражда каноничните конструктори от per-season `constructors` (групирани по
 * slug — един отбор = един ред), свързва всеки constructor запис чрез canonical_id
 * и изчислява агрегатните статистики от резултатите на пилотите на отбора.
 * Идемпотентно (updateOrCreate по slug).
 */
class CanonicalConstructorBackfiller
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
                'canonical' => ConstructorCanonical::query()->count(),
                'linked' => Constructor::query()->whereNotNull('canonical_id')->count(),
            ];
        });
    }

    private function createCanonicalsAndLink(): void
    {
        $bySlug = Constructor::query()
            ->orderBy('season_id')
            ->get(['id', 'slug', 'name', 'color_hex'])
            ->groupBy('slug');

        foreach ($bySlug as $slug => $rows) {
            $latest = $rows->last();           // най-нов сезон (подредени възходящо)
            $byRecency = $rows->reverse();

            $canonical = ConstructorCanonical::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $latest->name,
                    'color_hex' => $byRecency->firstWhere(fn (Constructor $c) => filled($c->color_hex))?->color_hex,
                ],
            );

            Constructor::query()->where('slug', $slug)->update(['canonical_id' => $canonical->id]);
        }
    }

    private function computeStats(): void
    {
        $race = ResultSessionType::Race->value;

        $agg = Result::query()
            ->selectRaw('constructors.canonical_id as cid, '
                ."COUNT(DISTINCT CASE WHEN results.session_type = '{$race}' THEN results.race_id END) as races, "
                ."SUM(CASE WHEN results.position = 1 AND results.session_type = '{$race}' THEN 1 ELSE 0 END) as wins, "
                ."SUM(CASE WHEN results.position BETWEEN 1 AND 3 AND results.session_type = '{$race}' THEN 1 ELSE 0 END) as podiums, "
                ."SUM(CASE WHEN results.grid_position = 1 AND results.session_type = '{$race}' THEN 1 ELSE 0 END) as poles, "
                .'MIN(races.race_datetime_utc) as first_at, MAX(races.race_datetime_utc) as last_at')
            ->join('drivers', 'drivers.id', '=', 'results.driver_id')
            ->join('constructors', 'constructors.id', '=', 'drivers.constructor_id')
            ->join('races', 'races.id', '=', 'results.race_id')
            ->whereNotNull('constructors.canonical_id')
            ->groupBy('constructors.canonical_id')
            ->get()
            ->keyBy('cid');

        $currentSeasonId = Season::query()->where('is_current', true)->value('id');
        $activeIds = $currentSeasonId
            ? Constructor::query()->where('season_id', $currentSeasonId)->whereNotNull('canonical_id')
                ->distinct()->pluck('canonical_id')->flip()
            : collect();

        ConstructorCanonical::query()->chunkById(200, function ($canonicals) use ($agg, $activeIds) {
            foreach ($canonicals as $canonical) {
                $a = $agg->get($canonical->id);

                $canonical->update([
                    'total_races' => (int) ($a->races ?? 0),
                    'total_wins' => (int) ($a->wins ?? 0),
                    'total_podiums' => (int) ($a->podiums ?? 0),
                    'total_poles' => (int) ($a->poles ?? 0),
                    'first_race_at' => $a->first_at ?? null,
                    'last_race_at' => $a->last_at ?? null,
                    'is_active' => $activeIds->has($canonical->id),
                ]);
            }
        });
    }
}
