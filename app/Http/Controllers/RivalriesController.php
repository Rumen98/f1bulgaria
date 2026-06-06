<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DriverCanonical;
use App\Models\Rivalry;
use App\Services\Drivers\ComparisonService;
use App\Support\CountryFlag;
use Inertia\Inertia;
use Inertia\Response;

class RivalriesController extends Controller
{
    public function __construct(private readonly ComparisonService $comparison) {}

    public function index(): Response
    {
        $rivalries = Rivalry::query()
            ->with(['driverOne', 'driverTwo'])
            ->orderByDesc('is_featured')
            ->orderByDesc('era_start_year')
            ->get()
            ->map(fn (Rivalry $r) => [
                'slug' => $r->slug,
                'title' => $r->title_bg,
                'description' => $r->description_bg,
                'era' => $this->era($r),
                'is_featured' => $r->is_featured,
                'one' => ['name' => $r->driverOne->fullName(), 'photo' => $r->driverOne->photo_url],
                'two' => ['name' => $r->driverTwo->fullName(), 'photo' => $r->driverTwo->photo_url],
            ]);

        return Inertia::render('Rivalries/Index', ['rivalries' => $rivalries]);
    }

    public function show(string $slug): Response
    {
        $rivalry = Rivalry::query()
            ->with(['driverOne', 'driverTwo'])
            ->where('slug', $slug)
            ->firstOrFail();

        return Inertia::render('Rivalries/Show', [
            'rivalry' => [
                'slug' => $rivalry->slug,
                'title' => $rivalry->title_bg,
                'description' => $rivalry->description_bg,
                'era' => $this->era($rivalry),
                'moments' => $rivalry->notable_moments ?? [],
            ],
            'a' => $this->header($rivalry->driverOne),
            'b' => $this->header($rivalry->driverTwo),
            'comparison' => $this->comparison->compare($rivalry->driverOne, $rivalry->driverTwo),
        ]);
    }

    private function era(Rivalry $r): ?string
    {
        if ($r->era_start_year === null) {
            return null;
        }

        return $r->era_end_year && $r->era_end_year !== $r->era_start_year
            ? "{$r->era_start_year}–{$r->era_end_year}"
            : (string) $r->era_start_year;
    }

    /**
     * @return array<string, mixed>
     */
    private function header(DriverCanonical $c): array
    {
        return [
            'slug' => $c->slug,
            'name' => $c->fullName(),
            'photo' => $c->photo_url,
            'flag' => CountryFlag::emoji($c->country_code),
        ];
    }
}
