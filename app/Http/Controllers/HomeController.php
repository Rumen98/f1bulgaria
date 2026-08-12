<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\NewsStatus;
use App\Models\RaceSession;
use App\Models\TeamNewsItem;
use App\Services\Hero\HeroRaceContext;
use App\Services\Hero\NextRaceResolver;
use App\Services\Homepage\ThisDayInF1Service;
use App\Services\LiveTiming\OpenF1Client;
use App\Services\LiveTiming\OpenF1TokenManager;
use App\Services\Predictions\PredictionLockService;
use App\Services\Races\RaceNameLocalizer;
use App\Support\DriverName;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function index(
        NextRaceResolver $resolver,
        ThisDayInF1Service $thisDay,
        OpenF1Client $openF1,
        OpenF1TokenManager $tokens,
        PredictionLockService $locks,
    ): Response {
        $hero = $resolver->resolve();

        return Inertia::render('Home', [
            'hero' => $this->heroProp($hero),
            'liveSession' => $this->liveSession($openF1, $tokens),
            'thisDay' => $thisDay->forDate(Carbon::now('Europe/Sofia')),
            'topNews' => $this->topNews(),
            'predictionCta' => $this->predictionCta($hero, $locks),
        ]);
    }

    /**
     * Покана към прогноза за предстоящия кръг.
     *
     * Причината да съществува: деветимата, които се връщат редовно, попадаха на
     * начална страница, която не ги канеше никъде — трябваше сами да се сетят за
     * лигата и да я намерят в менюто (измерено 12.08.2026: 9 връщащи се, 4
     * прогнозиращи).
     *
     * Гостът също го вижда, но с друг текст и към регистрация: човек, който се
     * записва ЗАРАДИ лигата, идва с намерение да играе — точно това липсва на
     * сегашните регистрации.
     *
     * @return array{race:string, url:string, deadline:?string, days:?int, guest:bool}|null
     */
    private function predictionCta(HeroRaceContext $hero, PredictionLockService $locks): ?array
    {
        $race = $hero->race;

        // Заключен кръг би водил към форма, която не приема — по-лошо от нищо.
        if ($race === null || $locks->isLocked($race)) {
            return null;
        }

        $user = request()->user();

        // Подсещане за нещо вече свършено обучава хората да игнорират банера.
        if ($user !== null && $user->predictions()->where('race_id', $race->id)->exists()) {
            return null;
        }

        $deadline = $locks->lockDeadline($race);

        return [
            'race' => $race->name_bg,
            'url' => $user !== null ? route('races.show', $race) : route('register'),
            'deadline' => $deadline?->copy()->setTimezone('Europe/Sofia')->format('d.m, H:i'),
            'days' => $deadline !== null ? (int) Carbon::now()->diffInDays($deadline, false) : null,
            'guest' => $user === null,
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
