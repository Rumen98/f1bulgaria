<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreGameVisitRequest;
use App\Models\GameVisit;
use App\Services\Game\GameVisitRecorder;
use Illuminate\Http\Response;

class GameVisitController extends Controller
{
    /** Отваряне на играта от всеки; „Карай" от гост — броим го, без да знаем кой е. */
    public function store(StoreGameVisitRequest $request, GameVisitRecorder $visits): Response
    {
        $data = $request->validated();

        if ($data['kind'] === GameVisit::KIND_START) {
            $visits->guestStart($request, $data['track'], $data['device']);
        } else {
            $visits->view($request, $data['device']);
        }

        return response()->noContent();
    }
}
