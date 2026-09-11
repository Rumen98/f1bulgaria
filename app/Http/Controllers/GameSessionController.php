<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreGameSessionRequest;
use Illuminate\Http\Response;

class GameSessionController extends Controller
{
    /**
     * Отбелязва „Карай" на регистриран потребител — следата „пробвал е
     * играта" за админа, независимо дали обиколката ще бъде завършена.
     * Клиентът праща fire-and-forget; отговорът няма тяло.
     */
    public function store(StoreGameSessionRequest $request): Response
    {
        $data = $request->validated();

        $request->user()->gameSessions()->create([
            'track_slug' => $data['track'],
            'device' => $data['device'],
            'mode' => $data['mode'],
        ]);

        return response()->noContent();
    }
}
