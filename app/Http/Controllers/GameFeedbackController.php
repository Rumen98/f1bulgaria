<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreGameFeedbackRequest;
use App\Services\Game\GameFeedbackService;
use Illuminate\Http\JsonResponse;

class GameFeedbackController extends Controller
{
    public function store(StoreGameFeedbackRequest $request, GameFeedbackService $feedback): JsonResponse
    {
        $feedback->store($request->user(), $request->validated());

        return response()->json(['message' => 'Благодарим! Мнението ти ще помогне да подобрим играта.'], 201);
    }
}
