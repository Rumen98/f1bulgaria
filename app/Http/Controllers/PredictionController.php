<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StorePredictionRequest;
use App\Http\Resources\PredictionResource;
use App\Models\Race;
use App\Services\Badges\BadgeService;
use App\Services\Predictions\PredictionLockService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PredictionController extends Controller
{
    public function index(): Response
    {
        $predictions = request()->user()
            ->predictions()
            ->with(['race', 'score'])
            ->get()
            ->sortByDesc(fn ($p) => $p->race->race_datetime_utc)
            ->values();

        return Inertia::render('Predictions/Index', [
            'predictions' => PredictionResource::collection($predictions),
        ]);
    }

    public function store(
        StorePredictionRequest $request,
        Race $race,
        PredictionLockService $lock,
        BadgeService $badges,
    ): RedirectResponse {
        if ($lock->isLocked($race)) {
            return back()->withErrors([
                'prediction' => 'Прогнозите за това състезание са заключени.',
            ]);
        }

        $request->user()->predictions()->updateOrCreate(
            ['race_id' => $race->id],
            $request->validated(),
        );

        // „Дебют" се дава ТУК, не при неделния синхрон: значката е обратна
        // връзка за действието и три дни закъснение я обезсмисля. award() е
        // идемпотентен — редакция на прогноза не прави нищо.
        $badges->awardDebut($request->user());
        $badges->awardStreak($request->user(), $race);

        return back()->with('success', 'Прогнозата е запазена.');
    }
}
