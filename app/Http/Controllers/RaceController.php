<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\PredictionResource;
use App\Http\Resources\RaceResource;
use App\Models\Driver;
use App\Models\Race;
use App\Services\Predictions\PredictionLockService;
use App\Support\BulgarianSort;
use App\Support\DriverName;
use App\Support\Seo;
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
        ]);
    }
}
