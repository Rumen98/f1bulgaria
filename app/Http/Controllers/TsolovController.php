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
            'profile' => $this->profile(),
        ]);
    }

    /**
     * Чете файла директно, а не през config() — кешираният config
     * (bootstrap/cache/config.php) иначе сервира старите стойности до следващо
     * `php artisan config:cache`. Така редакция + deploy е достатъчно.
     *
     * @return array<string, mixed>
     */
    private function profile(): array
    {
        return require config_path('tsolov.php');
    }
}
