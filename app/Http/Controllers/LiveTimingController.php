<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Race;
use App\Services\LiveTiming\LiveStandingsBuilder;
use App\Services\LiveTiming\OpenF1Client;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class LiveTimingController extends Controller
{
    public function __construct(
        private readonly OpenF1Client $client,
        private readonly LiveStandingsBuilder $builder,
    ) {}

    public function index(): Response
    {
        $payload = $this->payload();

        return Inertia::render('Live/Index', [
            ...$payload,
            'nextRace' => $payload['session'] === null ? $this->nextRace() : null,
        ]);
    }

    public function refresh(): JsonResponse
    {
        return response()->json($this->payload());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $session = $this->client->getCurrentSession();

        // Жива ли е сесията? (буфер: 30 мин преди старт до 30 мин след край).
        $isLive = $session !== null && $this->isLive($session);

        return [
            'session' => $isLive ? [
                'name' => $session['name'],
                'type' => $session['type'],
                'circuit' => $session['circuit_short_name'],
                'ends_at' => $session['date_end']?->toIso8601String(),
            ] : null,
            'standings' => $isLive ? $this->builder->build($session['key'], $session['type']) : collect(),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function isLive(array $session): bool
    {
        $start = $session['date_start'] ?? null;
        $end = $session['date_end'] ?? null;

        // Без дати — щом API върна сесия, приемаме я за активна (защитно).
        if ($start === null || $end === null) {
            return true;
        }

        return now()->between($start->copy()->subMinutes(30), $end->copy()->addMinutes(30));
    }

    /**
     * @return array{name:string, circuit:?string, starts_at:?string}|null
     */
    private function nextRace(): ?array
    {
        $race = Race::query()
            ->where('race_datetime_utc', '>', now())
            ->orderBy('race_datetime_utc')
            ->first();

        if ($race === null) {
            return null;
        }

        return [
            'name' => $race->name,
            'circuit' => $race->circuit,
            'starts_at' => $race->race_datetime_utc?->toIso8601String(),
        ];
    }
}
