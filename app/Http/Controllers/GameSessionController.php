<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreGameSessionEventsRequest;
use App\Http\Requests\StoreGameSessionRequest;
use App\Http\Resources\GameSessionResource;
use App\Models\GameSession;
use App\Services\Game\GameSessionTelemetry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class GameSessionController extends Controller
{
    public function store(StoreGameSessionRequest $request, GameSessionTelemetry $telemetry): Response|JsonResponse
    {
        $session = $telemetry->start($request->user(), $request->validated());

        if ($session->client_id !== null) {
            return (new GameSessionResource($session))->response()->setStatusCode(201);
        }

        return response()->noContent();
    }

    public function events(StoreGameSessionEventsRequest $request, GameSession $session, GameSessionTelemetry $telemetry): Response
    {
        $telemetry->record($session, $request->validated('events'));

        return response()->noContent();
    }
}
