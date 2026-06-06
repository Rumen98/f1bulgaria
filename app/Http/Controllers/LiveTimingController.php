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
        $session = $this->client->getLiveSession();

        $isQualifying = $session !== null && str_contains(
            mb_strtolower($session['type'].' '.$session['name']),
            'qualifying',
        );

        return [
            'session' => $session !== null ? [
                'name' => $session['name'],
                'type' => $session['type'],
                'circuit' => $session['circuit_short_name'],
                'ends_at' => $session['date_end']?->toIso8601String(),
                'is_qualifying' => $isQualifying,
                // Граници на отпадане в квалификацията (Q1 след P15, Q2 след P10).
                'cutoffs' => $isQualifying ? [15, 10] : [],
            ] : null,
            'standings' => $session !== null ? $this->builder->build($session['key'], $session['type']) : collect(),
            'updated_at' => now()->toIso8601String(),
        ];
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
