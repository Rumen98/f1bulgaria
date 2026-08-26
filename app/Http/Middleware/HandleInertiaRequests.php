<?php

namespace App\Http\Middleware;

use App\Services\Feedback\SurveyPromptService;
use App\Support\Seo;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
            ],
            'teamBrands' => fn () => config('team-brands'),
            'features' => fn () => config('features'),
            // Заглавието се решава сървърно (App\Support\Seo). Подава се и като
            // prop, за да го приложи клиентът при SPA навигация — така таб
            // заглавието и индексираното от Google съвпадат.
            'seoTitle' => fn () => app(Seo::class)->resolvedTitle(),
            'survey' => [
                // Една лека индексирана заявка на реквест; при мащаба на сайта
                // кеширане би било свръхинженерство.
                'shouldPrompt' => fn () => app(SurveyPromptService::class)->shouldPrompt($request->user()),
            ],
        ];
    }
}
