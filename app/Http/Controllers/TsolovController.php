<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class TsolovController extends Controller
{
    public function index(): Response
    {
        // Единствен източник: config/tsolov.php. F2 auto-sync е непълен (и изключен
        // в V1), затова НЕ го смесваме тук — иначе класирането се разминава с банера.
        return Inertia::render('Tsolov', [
            'profile' => config('tsolov'),
        ]);
    }
}
