<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Driver;
use App\Models\DriverCanonical;
use App\Services\Drivers\ComparisonService;
use App\Support\BulgarianSort;
use App\Support\CountryFlag;
use App\Support\DriverName;
use Inertia\Inertia;
use Inertia\Response;

class CompareController extends Controller
{
    public function __construct(private readonly ComparisonService $comparison) {}

    public function index(): Response
    {
        return Inertia::render('Compare/Index', [
            'drivers' => $this->driverOptions(),
            'presets' => [
                ['label' => 'Senna vs Prost', 'a' => 'ayrton-senna', 'b' => 'alain-prost'],
                ['label' => 'Hamilton vs Schumacher', 'a' => 'lewis-hamilton', 'b' => 'michael-schumacher'],
                ['label' => 'Verstappen vs Hamilton', 'a' => 'max-verstappen', 'b' => 'lewis-hamilton'],
                ['label' => 'Prost vs Mansell', 'a' => 'alain-prost', 'b' => 'nigel-mansell'],
            ],
        ]);
    }

    public function show(string $slug1, string $slug2): Response
    {
        $a = DriverCanonical::query()->where('slug', $slug1)->first();
        $b = DriverCanonical::query()->where('slug', $slug2)->first();

        abort_if($a === null || $b === null, 404);

        return Inertia::render('Compare/Show', [
            'a' => $this->driverHeader($a),
            'b' => $this->driverHeader($b),
            'comparison' => $this->comparison->compare($a, $b),
        ]);
    }

    /**
     * @return array<int, array{slug:string, name:string, wins:int, is_active:bool}>
     */
    private function driverOptions(): array
    {
        return DriverCanonical::query()
            ->get(['slug', 'first_name', 'last_name', 'total_wins', 'is_active'])
            ->map(fn (DriverCanonical $c) => [
                'slug' => $c->slug,
                'name' => DriverName::display($c->slug, $c->fullName()),
                'wins' => $c->total_wins,
                'is_active' => $c->is_active,
            ])
            // Победите водят (празното поле показва най-успешните), но при
            // равенство редът е азбучен на кирилица. Сортирането е на два
            // етапа, защото sort-ът на PHP 8 е стабилен и пази имената.
            ->sortBy(fn (array $d) => BulgarianSort::key($d['name']))
            ->sortByDesc('wins')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function driverHeader(DriverCanonical $c): array
    {
        $latest = Driver::query()
            ->where('canonical_id', $c->id)
            ->with('constructor')
            ->orderByDesc('season_id')
            ->first();

        return [
            'slug' => $c->slug,
            'name' => DriverName::display($c->slug, $c->fullName()),
            'code' => $c->code,
            'photo' => $c->photo_url ?? $latest?->photo_url,
            'flag' => CountryFlag::iso2($c->country_code),
            'team' => $latest?->constructor?->name,
            'color_hex' => $latest?->constructor?->color_hex ?? '#e10600',
            'is_active' => $c->is_active,
        ];
    }
}
