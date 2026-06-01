<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\PredictionResource;
use App\Http\Resources\RaceResource;
use App\Models\Driver;
use App\Models\Race;
use App\Services\Predictions\PredictionLockService;
use Inertia\Inertia;
use Inertia\Response;

class RaceController extends Controller
{
    public function show(Race $race, PredictionLockService $lock): Response
    {
        $race->load([
            'sessions',
            'poleDriver',
            'results' => fn ($q) => $q->with('driver.constructor')->orderByRaw('position is null, position'),
        ]);

        $userPrediction = null;

        if ($user = request()->user()) {
            $prediction = $user->predictions()
                ->where('race_id', $race->id)
                ->with('score')
                ->first();

            $userPrediction = $prediction ? new PredictionResource($prediction) : null;
        }

        $drivers = Driver::query()
            ->where('season_id', $race->season_id)
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn ($d) => ['id' => $d->id, 'name' => $d->fullName()]);

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
