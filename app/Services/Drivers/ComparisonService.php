<?php

declare(strict_types=1);

namespace App\Services\Drivers;

use App\Enums\ResultSessionType;
use App\Models\Driver;
use App\Models\DriverCanonical;
use App\Models\Result;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Сравнение между двама канонични пилоти: кариерни числа, припокриване на ери,
 * head-to-head (ако са карали по едно и също време) и общи писти.
 */
class ComparisonService
{
    /**
     * @return array{
     *     career: array{a: array<string,mixed>, b: array<string,mixed>},
     *     era_overlap: ?array{start_year:int, end_year:int, seasons_count:int},
     *     head_to_head: ?array<string,mixed>,
     *     common_circuits: Collection<int, array<string,mixed>>
     * }
     */
    public function compare(DriverCanonical $a, DriverCanonical $b): array
    {
        // Фингърпринт по статистиката, за да се обезсили кешът при промяна на
        // данните (нов backfill) — и да не се преплита между изолирани тестове.
        $fp = "{$a->total_races}-{$a->total_wins}-{$b->total_races}-{$b->total_wins}";

        return Cache::remember("compare:{$a->id}-{$b->id}:{$fp}", now()->addDay(), function () use ($a, $b) {
            $eraOverlap = $this->eraOverlap($a, $b);

            return [
                'career' => [
                    'a' => $this->career($a),
                    'b' => $this->career($b),
                ],
                'era_overlap' => $eraOverlap,
                'head_to_head' => $eraOverlap !== null ? $this->headToHead($a, $b) : null,
                'common_circuits' => $this->commonCircuits($a, $b),
            ];
        });
    }

    /**
     * @return array{wins:int, podiums:int, poles:int, races:int, win_rate:float, first_year:?int, last_year:?int}
     */
    private function career(DriverCanonical $c): array
    {
        return [
            'wins' => $c->total_wins,
            'podiums' => $c->total_podiums,
            'poles' => $c->total_poles,
            'races' => $c->total_races,
            'win_rate' => $c->total_races > 0 ? round($c->total_wins / $c->total_races * 100, 1) : 0.0,
            'first_year' => $c->first_race_at?->year,
            'last_year' => $c->last_race_at?->year,
        ];
    }

    /**
     * @return array{start_year:int, end_year:int, seasons_count:int}|null
     */
    private function eraOverlap(DriverCanonical $a, DriverCanonical $b): ?array
    {
        $aStart = $a->first_race_at?->year;
        $aEnd = $a->last_race_at?->year;
        $bStart = $b->first_race_at?->year;
        $bEnd = $b->last_race_at?->year;

        if ($aStart === null || $aEnd === null || $bStart === null || $bEnd === null) {
            return null;
        }

        $start = max($aStart, $bStart);
        $end = min($aEnd, $bEnd);

        if ($start > $end) {
            return null;
        }

        return ['start_year' => $start, 'end_year' => $end, 'seasons_count' => $end - $start + 1];
    }

    /**
     * Head-to-head по състезанията, в които ДВАМАТА имат резултат (race сесия):
     * по-добро класиране и по-добра стартова позиция.
     *
     * @return array{races_together:int, qualifying:array{a:int,b:int,ties:int}, race:array{a:int,b:int,ties:int}, dnfs:array{a:int,b:int}}
     */
    private function headToHead(DriverCanonical $a, DriverCanonical $b): array
    {
        $aRes = $this->raceResultsByRace($a);
        $bRes = $this->raceResultsByRace($b);

        $common = $aRes->keys()->intersect($bRes->keys());

        $quali = ['a' => 0, 'b' => 0, 'ties' => 0];
        $race = ['a' => 0, 'b' => 0, 'ties' => 0];
        $dnfs = ['a' => 0, 'b' => 0];

        foreach ($common as $raceId) {
            $ra = $aRes[$raceId];
            $rb = $bRes[$raceId];

            // Квалификация: по-малка стартова позиция е по-добра.
            $ga = $ra->grid_position;
            $gb = $rb->grid_position;
            if ($ga !== null && $gb !== null) {
                if ($ga < $gb) {
                    $quali['a']++;
                } elseif ($gb < $ga) {
                    $quali['b']++;
                } else {
                    $quali['ties']++;
                }
            }

            // Класиране: по-малка позиция е по-добра; null (DNF/незавършил) е по-лошо.
            $pa = $ra->position;
            $pb = $rb->position;
            if ($pa !== null && $pb !== null) {
                if ($pa < $pb) {
                    $race['a']++;
                } elseif ($pb < $pa) {
                    $race['b']++;
                } else {
                    $race['ties']++;
                }
            } elseif ($pa !== null) {
                $race['a']++;
            } elseif ($pb !== null) {
                $race['b']++;
            }

            if ($ra->dnf) {
                $dnfs['a']++;
            }
            if ($rb->dnf) {
                $dnfs['b']++;
            }
        }

        return [
            'races_together' => $common->count(),
            'qualifying' => $quali,
            'race' => $race,
            'dnfs' => $dnfs,
        ];
    }

    /**
     * @return Collection<int, object> ключ = race_id
     */
    private function raceResultsByRace(DriverCanonical $c): Collection
    {
        $ids = Driver::query()->where('canonical_id', $c->id)->pluck('id');

        return Result::query()
            ->whereIn('driver_id', $ids)
            ->where('session_type', ResultSessionType::Race->value)
            ->get(['race_id', 'position', 'grid_position', 'dnf'])
            ->keyBy('race_id');
    }

    /**
     * До 5 писти, на които и двамата са карали — с най-доброто класиране на всеки.
     *
     * @return Collection<int, array{circuit_slug:string, circuit:string, a_best:?int, b_best:?int}>
     */
    private function commonCircuits(DriverCanonical $a, DriverCanonical $b): Collection
    {
        $aStats = $this->circuitBests($a);
        $bStats = $this->circuitBests($b);

        $common = $aStats->keys()->intersect($bStats->keys());

        return $common
            ->map(fn ($slug) => [
                'circuit_slug' => $slug,
                'circuit' => $aStats[$slug]['name'],
                'a_best' => $aStats[$slug]['best'],
                'b_best' => $bStats[$slug]['best'],
                'a_races' => $aStats[$slug]['races'],
                'b_races' => $bStats[$slug]['races'],
            ])
            ->sortByDesc(fn ($row) => $row['a_races'] + $row['b_races'])
            ->take(5)
            ->values();
    }

    /**
     * @return Collection<string, array{name:string, best:?int, races:int}> ключ = jolpica_id
     */
    private function circuitBests(DriverCanonical $c): Collection
    {
        $ids = Driver::query()->where('canonical_id', $c->id)->pluck('id');

        return Result::query()
            ->selectRaw('races.jolpica_id as slug, MIN(races.circuit) as name, '
                .'MIN(results.position) as best, COUNT(DISTINCT results.race_id) as races')
            ->join('races', 'races.id', '=', 'results.race_id')
            ->whereIn('results.driver_id', $ids)
            ->where('results.session_type', ResultSessionType::Race->value)
            ->whereNotNull('races.jolpica_id')
            ->groupBy('races.jolpica_id')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->slug => [
                'name' => $r->name,
                'best' => $r->best !== null ? (int) $r->best : null,
                'races' => (int) $r->races,
            ]]);
    }
}
