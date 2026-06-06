<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class TerminologyController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Terminologiya/Index', [
            'categories' => config('f1-glossary.categories'),
            'terms' => config('f1-glossary.terms'),
        ]);
    }
}
