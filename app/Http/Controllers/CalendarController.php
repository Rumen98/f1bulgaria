<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\RaceResource;
use App\Models\Season;
use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
    public function index(): Response
    {
        $season = Season::current();

        $races = $season
            ? $season->races()->with(['results', 'sessions'])->orderBy('round')->get()
            : collect();

        return Inertia::render('Races/Calendar', [
            'season' => $season?->year,
            'races' => RaceResource::collection($races),
        ]);
    }
}
