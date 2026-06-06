<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\F2Driver;
use App\Models\F2Result;
use Inertia\Inertia;
use Inertia\Response;

class TsolovController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tsolov', [
            'profile' => config('tsolov'),
            'f2' => $this->f2Snapshot(),
        ]);
    }

    /**
     * Текущото състояние на Цолов във F2 (ако е синхронизиран). null иначе.
     *
     * @return array<string, mixed>|null
     */
    private function f2Snapshot(): ?array
    {
        $driver = F2Driver::query()
            ->where('slug', 'nikola-tsolov')
            ->whereHas('season', fn ($q) => $q->where('is_current', true))
            ->with(['season', 'team'])
            ->first();

        if ($driver === null) {
            return null;
        }

        $results = F2Result::query()->where('f2_driver_id', $driver->id);

        $latest = (clone $results)
            ->with('session.race')
            ->get()
            ->sortByDesc(fn (F2Result $r) => $r->session?->race?->round ?? 0)
            ->first();

        return [
            'season' => $driver->season?->year,
            'team' => $driver->team?->name,
            'position' => $driver->position,
            'points' => $driver->points,
            'wins' => (clone $results)->where('position', 1)->count(),
            'podiums' => (clone $results)->whereNotNull('position')->where('position', '<=', 3)->count(),
            'latest' => $latest ? [
                'location' => $latest->session?->race?->location_name,
                'session' => $latest->session?->session_type?->label(),
                'position' => $latest->position,
                'race_slug' => $latest->session?->race?->slug,
                'session_type' => $latest->session?->session_type?->value === 'feature_race' ? 'feature' : 'sprint',
            ] : null,
        ];
    }
}
