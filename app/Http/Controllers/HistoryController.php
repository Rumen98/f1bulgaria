<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class HistoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('History/Index', [
            'hero' => config('history-content.hero'),
            'sections' => config('history-content.sections'),
        ]);
    }
}
