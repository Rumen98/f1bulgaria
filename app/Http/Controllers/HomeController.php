<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\NewsStatus;
use App\Models\RaceSession;
use App\Models\TeamNewsItem;
use App\Services\Game\LeaderboardService;
use App\Services\Game\WeekTrackResolver;
use App\Services\Hero\HeroRaceContext;
use App\Services\Hero\NextRaceResolver;
use App\Services\Homepage\ThisDayInF1Service;
use App\Services\LiveTiming\OpenF1Client;
use App\Services\LiveTiming\OpenF1TokenManager;
use App\Services\Races\RaceNameLocalizer;
use App\Support\DriverName;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function index(NextRaceResolver $resolver, ThisDayInF1Service $thisDay, OpenF1Client $openF1, OpenF1TokenManager $tokens): Response
    {
        return Inertia::render('Home', [
            'hero' => $this->heroProp($resolver->resolve()),
            'liveSession' => $this->liveSession($openF1, $tokens),
            'thisDay' => $thisDay->forDate(Carbon::now('Europe/Sofia')),
            'topNews' => $this->topNews(),
            'gameTeaser' => $this->gameTeaser(),
        ]);
    }

    /**
     * Тийзърът на Хронометъра: пистата на уикенда + топ 3 времена (седмични,
     * с резервен вариант всички времена). Играта иначе е невидима за
     * никога-не-кликналите „Хронометър".
     *
     * @return array{slug: string, name: string, top: array<int, array<string, mixed>>}|null
     */
    private function gameTeaser(): ?array
    {
        if (! config('features.game')) {
            return null;
        }

        $week = app(WeekTrackResolver::class)->resolve();

        if ($week === null) {
            return null;
        }

        $leaderboard = app(LeaderboardService::class);
        $top = $leaderboard->topLaps($week['slug'], null, 3, $week['week_start']);
        if ($top->isEmpty()) {
            $top = $leaderboard->topLaps($week['slug'], null, 3);
        }

        $name = collect($leaderboard->trackIndex())
            ->firstWhere('slug', $week['slug'])['name'] ?? $week['slug'];

        return [
            'slug' => $week['slug'],
            'name' => (string) $name,
            'top' => $top->values()->all(),
        ];
    }

    /**
     * Лек проверител за активна сесия (кеширан 60s в клиента) — за live банера.
     *
     * @return array{name:string, circuit:?string}|null
     */
    private function liveSession(OpenF1Client $openF1, OpenF1TokenManager $tokens): ?array
    {
        // Без OpenF1 кредитали live достъпът е блокиран по време на сесии (401),
        // затова не правим излишна заявка на всяко зареждане на началната страница.
        if (! $tokens->hasCredentials()) {
            return null;
        }

        $session = $openF1->getLiveSession();

        return $session !== null
            ? ['name' => $session['name'], 'circuit' => $session['circuit_short_name']]
            : null;
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
                'name' => app(RaceNameLocalizer::class)->localize($ctx->race->jolpica_id, $ctx->race->name),
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
            'winner' => $ctx->winner
                ? ['name' => DriverName::display($ctx->winner->slug, $ctx->winner->fullName())]
                : null,
        ];
    }
}
