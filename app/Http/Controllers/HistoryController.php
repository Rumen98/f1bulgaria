<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DriverCanonical;
use Inertia\Inertia;
use Inertia\Response;

class HistoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('History/Index');
    }

    public function world(): Response
    {
        $content = config('history-world-content');

        // Само легендите с реален каноничен запис (за да не водят линковете към 404).
        $existing = DriverCanonical::query()
            ->whereIn('slug', collect($content['legends'])->pluck('slug'))
            ->pluck('slug')
            ->flip();

        $legends = collect($content['legends'])->filter(fn ($l) => $existing->has($l['slug']))->values();

        return Inertia::render('History/World', [
            'hero' => $content['hero'],
            'eras' => $content['eras'],
            'legends' => $legends,
        ]);
    }

    public function bulgaria(): Response
    {
        return Inertia::render('History/Bulgaria', [
            'hero' => config('history-content.hero'),
            'sections' => config('history-content.sections'),
        ]);
    }
}
