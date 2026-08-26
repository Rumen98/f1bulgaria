<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreSurveyResponseRequest;
use App\Support\Seo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FeedbackController extends Controller
{
    public function show(): Response
    {
        app(Seo::class)
            ->title('Обратна връзка')
            ->description('Кажи ни какво да подобрим в Падок — оценка, идеи и предложения от общността.');

        return Inertia::render('Feedback/Index');
    }

    public function store(StoreSurveyResponseRequest $request): RedirectResponse
    {
        $request->user()->surveyResponses()->create([
            ...$request->validated(),
            'submitted_at' => now(),
        ]);

        return back()->with('success', 'Благодарим за обратната връзка!');
    }

    public function dismiss(Request $request): RedirectResponse
    {
        // Скриването също се записва: то е отговор „не сега" и нулира
        // 6-месечния цикъл, за да не се появи картата на следващата заявка.
        $request->user()->surveyResponses()->create([
            'source' => 'prompt',
            'dismissed_at' => now(),
        ]);

        return back();
    }
}
