<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SearchRequest;
use App\Services\Search\SiteSearchService;
use App\Support\Seo;
use Inertia\Inertia;
use Inertia\Response;

class SearchController extends Controller
{
    public function __invoke(SearchRequest $request, SiteSearchService $search): Response
    {
        $term = $request->term();
        $results = $search->search($term);

        app(Seo::class)
            ->title($term !== '' ? "Търсене: {$term}" : 'Търсене')
            ->description('Търси пилоти, отбори, състезания, писти, новини и термини в Падок.')
            // Резултатните страници нямат стойност в индекса и разреждат
            // авторитета на реалните страници.
            ->noindex();

        return Inertia::render('Search/Index', [
            'term' => $term,
            'groups' => $results['groups'],
            'total' => $results['total'],
            'minLength' => SiteSearchService::MIN_LENGTH,
        ]);
    }
}
