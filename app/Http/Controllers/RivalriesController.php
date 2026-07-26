<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DriverCanonical;
use App\Models\Rivalry;
use App\Services\Drivers\ComparisonService;
use App\Support\BulgarianSort;
use App\Support\CountryFlag;
use App\Support\DriverName;
use App\Support\Seo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
            ->orderBy('is_custom') // официалните преди потребителските
            ->orderByDesc('era_start_year')
            ->get()
            ->map(fn (Rivalry $r) => [
                'slug' => $r->slug,
                'title' => $r->title_bg,
                'description' => $r->description_bg,
                'era' => $this->era($r),
                'is_featured' => $r->is_featured,
                'is_custom' => $r->is_custom,
                'one' => [
                    'name' => DriverName::display($r->driverOne->slug, $r->driverOne->fullName()),
                    'photo' => $r->driverOne->photo_url,
                ],
                'two' => [
                    'name' => DriverName::display($r->driverTwo->slug, $r->driverTwo->fullName()),
                    'photo' => $r->driverTwo->photo_url,
                ],
            ]);

        return Inertia::render('Rivalries/Index', [
            'rivalries' => $rivalries,
            'canCreate' => auth()->check(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Rivalries/Create', [
            'drivers' => DriverCanonical::query()
                ->get(['slug', 'first_name', 'last_name', 'total_wins'])
                ->map(fn (DriverCanonical $c) => [
                    'slug' => $c->slug,
                    'name' => DriverName::display($c->slug, $c->fullName()),
                    'wins' => $c->total_wins,
                ])
                // Победите водят (празното поле показва най-успешните), но при
                // равенство редът е азбучен на кирилица. Сортирането е на два
                // етапа, защото sort-ът на PHP 8 е стабилен и пази имената.
                ->sortBy(fn (array $d) => BulgarianSort::key($d['name']))
                ->sortByDesc('wins')
                ->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'driver_one' => ['required', 'string', 'exists:drivers_canonical,slug'],
            'driver_two' => ['required', 'string', 'different:driver_one', 'exists:drivers_canonical,slug'],
            'title' => ['nullable', 'string', 'max:120'],
        ]);

        $one = DriverCanonical::query()->where('slug', $data['driver_one'])->firstOrFail();
        $two = DriverCanonical::query()->where('slug', $data['driver_two'])->firstOrFail();

        $title = ($data['title'] ?? null) ?: "{$one->last_name} срещу {$two->last_name}";
        $slug = $this->uniqueSlug("{$one->slug}-vs-{$two->slug}");

        Rivalry::query()->create([
            'slug' => $slug,
            'user_id' => $request->user()->id,
            'driver_one_canonical_id' => $one->id,
            'driver_two_canonical_id' => $two->id,
            'era_start_year' => max($one->first_race_at?->year, $two->first_race_at?->year) ?: null,
            'era_end_year' => min($one->last_race_at?->year, $two->last_race_at?->year) ?: null,
            'title_bg' => $title,
            'description_bg' => null,
            'notable_moments' => [],
            'is_featured' => false,
            'is_custom' => true,
        ]);

        return redirect()->route('rivalries.show', $slug)->with('success', 'Дуелът е създаден!');
    }

    private function uniqueSlug(string $base): string
    {
        $slug = Str::slug($base);
        $candidate = $slug;
        $i = 2;

        while (Rivalry::query()->where('slug', $candidate)->exists()) {
            $candidate = "{$slug}-{$i}";
            $i++;
        }

        return $candidate;
    }

    public function show(string $slug): Response
    {
        $rivalry = Rivalry::query()
            ->with(['driverOne', 'driverTwo'])
            ->where('slug', $slug)
            ->firstOrFail();

        app(Seo::class)
            ->title($rivalry->title_bg)
            ->description($rivalry->description_bg)
            ->canonical(route('rivalries.show', $slug));

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
            'name' => DriverName::display($c->slug, $c->fullName()),
            'photo' => $c->photo_url,
            'flag' => CountryFlag::iso2($c->country_code),
        ];
    }
}
