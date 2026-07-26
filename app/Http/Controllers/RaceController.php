<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ResultSessionType;
use App\Enums\SessionType;
use App\Http\Resources\PredictionResource;
use App\Http\Resources\RaceResource;
use App\Models\Driver;
use App\Models\Race;
use App\Models\Result;
use App\Models\SessionResult;
use App\Services\Predictions\PredictionLockService;
use App\Support\BulgarianSort;
use App\Support\DriverName;
use App\Support\Seo;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class RaceController extends Controller
{
    public function show(Race $race, PredictionLockService $lock): Response
    {
        $race->load([
            'sessions',
            'poleDriver',
            'results' => fn ($q) => $q->where('session_type', 'race')
                ->with('driver.constructor')
                ->orderByRaw('position is null, position'),
        ]);

        $userPrediction = null;

        if ($user = request()->user()) {
            $prediction = $user->predictions()
                ->where('race_id', $race->id)
                ->with('score')
                ->first();

            $userPrediction = $prediction ? new PredictionResource($prediction) : null;
        }

        // Падащото меню за прогнози е азбучно по показваното (кирилско) име,
        // затова подредбата е след map-ването, а не в SQL.
        $drivers = Driver::query()
            ->where('season_id', $race->season_id)
            ->get(['id', 'slug', 'first_name', 'last_name'])
            ->map(fn ($d) => ['id' => $d->id, 'name' => DriverName::display($d->slug, $d->fullName())])
            ->sortBy(fn (array $d) => BulgarianSort::key($d['name']))
            ->values();

        app(Seo::class)
            ->title($race->name_bg ?? $race->name)
            ->description(($race->name_bg ?? $race->name).' — програма, стартова решетка и резултати от Формула 1. Часове в българско време.')
            ->canonical(route('races.show', $race->id));

        return Inertia::render('Races/Show', [
            'race' => new RaceResource($race),
            'locked' => $lock->isLocked($race),
            'lockDeadline' => $lock->lockDeadline($race)
                ?->setTimezone('Europe/Sofia')->format('d.m.Y H:i'),
            'userPrediction' => $userPrediction,
            'drivers' => $drivers,
            'classifications' => $this->classifications($race),
        ]);
    }

    /**
     * Класациите от ВСИЧКИ сесии на уикенда, в реда на провеждането им.
     *
     * Дотук страницата показваше само състезанието, тоест до неделя следобед
     * (а при забавяне на Jolpica — и след това) стоеше празна, макар
     * квалификацията отдавна да е в базата.
     *
     * Два източника: `results` носи сесиите с точки, `session_results` —
     * останалите. Разделени са нарочно (виж миграцията на `session_results`).
     *
     * @return array<int, array{type:string, label:string, rows:array<int, array<string, mixed>>}>
     */
    private function classifications(Race $race): array
    {
        $sections = [];

        foreach (Result::query()
            ->where('race_id', $race->id)
            ->with('driver.constructor')
            ->get()
            ->groupBy(fn (Result $r): string => $r->session_type->value) as $type => $rows
        ) {
            $sessionType = $type === ResultSessionType::Sprint->value
                ? SessionType::Sprint
                : SessionType::Race;

            $sections[] = [
                'type' => $sessionType->value,
                'label' => $sessionType->label(),
                'order' => $sessionType->order(),
                'rows' => $this->rows($rows, withPoints: true),
            ];
        }

        foreach (SessionResult::query()
            ->where('race_id', $race->id)
            ->with('driver.constructor')
            ->get()
            ->groupBy(fn (SessionResult $r): string => $r->session_type->value) as $type => $rows
        ) {
            $sessionType = SessionType::from((string) $type);

            $sections[] = [
                'type' => $sessionType->value,
                'label' => $sessionType->label(),
                'order' => $sessionType->order(),
                'rows' => $this->rows($rows, withPoints: false),
            ];
        }

        usort($sections, fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_map(fn (array $s): array => Arr::except($s, 'order'), $sections);
    }

    /**
     * @param  Collection<int, Result|SessionResult>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function rows(Collection $rows, bool $withPoints): array
    {
        return $rows
            // Некласираните (DNF, без време) отиват най-долу, а не най-горе,
            // както би ги подредил null при обикновено сортиране.
            ->sortBy(fn ($r): int => $r->position ?? 999)
            ->values()
            ->map(fn ($r): array => [
                'position' => $r->position,
                'driver' => $r->driver ? DriverName::display($r->driver->slug, $r->driver->fullName()) : null,
                'slug' => $r->driver?->slug,
                'team' => $r->driver?->constructor?->name,
                'time' => $r instanceof SessionResult ? $r->bestQualifyingTime() : null,
                'gap' => $r instanceof SessionResult ? $r->gap : null,
                'points' => $withPoints ? (float) $r->points : null,
                'dnf' => $r instanceof Result ? (bool) $r->dnf : false,
                'fastest_lap' => $r instanceof Result ? (bool) $r->fastest_lap : false,
            ])
            ->all();
    }
}
