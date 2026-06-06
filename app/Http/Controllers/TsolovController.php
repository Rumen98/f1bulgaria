<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\F2Driver;
use Inertia\Inertia;
use Inertia\Response;

class TsolovController extends Controller
{
    public function index(): Response
    {
        // Връзка към F2, ако Цолов има запис там (за „Виж във Формула 2").
        $f2 = F2Driver::query()->where('slug', 'nikola-tsolov')->with('season')->first();

        return Inertia::render('Tsolov', [
            'profile' => config('tsolov'),
            'f2Season' => $f2?->season?->year,
        ]);
    }
}
