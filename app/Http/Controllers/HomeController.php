<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\NewsStatus;
use App\Models\RaceSession;
use App\Models\TeamNewsItem;
use App\Services\Hero\HeroRaceContext;
use App\Services\Hero\NextRaceResolver;
use App\Services\Homepage\ThisDayInF1Service;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function index(NextRaceResolver $resolver, ThisDayInF1Service $thisDay): Response
    {
        return Inertia::render('Home', [
            'hero' => $this->heroProp($resolver->resolve()),
            'thisDay' => $thisDay->forDate(Carbon::now('Europe/Sofia')),
            'topNews' => $this->topNews(),
        ]);
    }

    /**
     * Последни одобрени новини за заглавната страница.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function topNews()
    {
        return TeamNewsItem::query()
            ->whereIn('status', collect(NewsStatus::publiclyVisible())->map->value->all())
            ->whereNotNull('title_bg')
            ->with('constructor')
            ->orderByDesc('published_at')
            ->limit(6)
            ->get()
            ->map(fn (TeamNewsItem $i) => [
                'slug' => $i->slug,
                'title' => $i->title_bg,
                'summary' => $i->summary_bg,
                'classification' => $i->classification?->label(),
                'importance' => $i->importance_score,
                'team' => $i->constructor?->name,
                'color' => $i->constructor?->color_hex,
                'image' => $i->featured_image,
                'published_at' => $i->published_at?->copy()->setTimezone('Europe/Sofia')->format('d.m.Y H:i'),
                'url' => $i->external_url,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function heroProp(HeroRaceContext $ctx): array
    {
        return [
            'state' => $ctx->state->value,
            'circuit_slug' => $ctx->circuitSlug,
            'countdown_to' => $ctx->countdownTo?->toIso8601String(),
            // Времето на сесията, към която броим (а НЕ времето на състезанието).
            'countdown_at_sofia' => $ctx->countdownTo
                ?->copy()->setTimezone('Europe/Sofia')->format('d.m.Y H:i'),
            'countdown_label' => $ctx->countdownLabel,
            'race' => $ctx->race ? [
                'id' => $ctx->race->id,
                'round' => $ctx->race->round,
                'name' => $ctx->race->name,
                'circuit' => $ctx->race->circuit,
                'country' => $ctx->race->country,
                'race_at_sofia' => $ctx->race->race_datetime_utc
                    ?->copy()->setTimezone('Europe/Sofia')->format('d.m.Y H:i'),
            ] : null,
            'sessions' => $ctx->sessions->map(fn (RaceSession $s) => [
                'type' => $s->type->value,
                'label' => $s->type->label(),
                'at_sofia' => $s->scheduled_at_utc
                    ?->copy()->setTimezone('Europe/Sofia')->format('d.m H:i'),
            ])->values(),
            'winner' => $ctx->winner ? ['name' => $ctx->winner->fullName()] : null,
        ];
    }
}
