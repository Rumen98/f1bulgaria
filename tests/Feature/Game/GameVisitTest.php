<?php

declare(strict_types=1);

use App\Filament\Resources\GamePlayers\Widgets\GameVisitsOverview;
use App\Models\GameSession;
use App\Models\GameVisit;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Livewire\Livewire;

beforeEach(function () {
    config(['features.game' => true]);
});

it('брои отварянето на играта от гост с дневен хеш, без личен идентификатор', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])
        ->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile Safari')
        ->postJson(route('game.visit.store'), ['kind' => 'view', 'device' => 'mobile'])
        ->assertNoContent();

    $visit = GameVisit::query()->sole();

    expect($visit->kind)->toBe(GameVisit::KIND_VIEW)
        ->and($visit->user_id)->toBeNull()
        ->and($visit->device)->toBe('mobile')
        ->and($visit->track_slug)->toBeNull()
        ->and($visit->visitor_key)->toHaveLength(64)
        ->and($visit->visitor_key)->not->toContain('10.0.0.7');
});

it('отварянето на страницата само по себе си не брои — prefetch-ът на менюто е неразличим', function () {
    $this->get(route('game'))->assertOk();

    expect(GameVisit::query()->count())->toBe(0);
});

it('дава един и същ ключ на същия гост в същия софийски ден и различен на следващия', function () {
    $headers = ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/128'];
    $payload = ['kind' => 'view', 'device' => 'desktop'];
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.8'])->withHeaders($headers)->postJson(route('game.visit.store'), $payload)->assertNoContent();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.8'])->withHeaders($headers)->postJson(route('game.visit.store'), $payload)->assertNoContent();

    expect(GameVisit::query()->distinct()->count('visitor_key'))->toBe(1);

    $this->travel(1)->days();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.8'])->withHeaders($headers)->postJson(route('game.visit.store'), $payload)->assertNoContent();

    expect(GameVisit::query()->distinct()->count('visitor_key'))->toBe(2);
});

it('не брои ботове и headless клиенти', function () {
    $this->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)')
        ->postJson(route('game.visit.store'), ['kind' => 'view', 'device' => 'desktop'])->assertNoContent();
    $this->withHeader('User-Agent', 'HeadlessChrome/128')
        ->postJson(route('game.visit.store'), ['kind' => 'view', 'device' => 'desktop'])->assertNoContent();

    expect(GameVisit::query()->count())->toBe(0);
});

it('спира на дневния таван за един посетител', function () {
    // Throttle-ът (30/мин) пази преди тавана; тук гледаме самия таван.
    $this->withoutMiddleware(ThrottleRequests::class);
    $headers = ['User-Agent' => 'Mozilla/5.0 Chrome/128'];
    foreach (range(1, 201) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])->withHeaders($headers)
            ->postJson(route('game.visit.store'), ['kind' => 'view', 'device' => 'desktop'])->assertNoContent();
    }

    expect(GameVisit::query()->count())->toBe(200);
});

it('отбелязва регистрирания посетител с user_id, а админа не брои', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('game.visit.store'), ['kind' => 'view', 'device' => 'desktop'])->assertNoContent();
    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->postJson(route('game.visit.store'), ['kind' => 'view', 'device' => 'desktop'])->assertNoContent();

    expect(GameVisit::query()->count())->toBe(1)
        ->and(GameVisit::query()->sole()->user_id)->not->toBeNull();
});

it('записва гост-опит с пистата и отказва същото на регистриран (той минава през сесията)', function () {
    $this->postJson(route('game.visit.store'), ['kind' => 'start', 'track' => 'spa', 'device' => 'desktop'])->assertNoContent();

    $start = GameVisit::query()->where('kind', GameVisit::KIND_START)->sole();
    expect($start->track_slug)->toBe('spa')->and($start->user_id)->toBeNull();

    $this->postJson(route('game.visit.store'), ['kind' => 'start', 'track' => 'nordschleife', 'device' => 'desktop'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('track');
    $this->postJson(route('game.visit.store'), ['kind' => 'start', 'device' => 'desktop'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('track');

    $this->actingAs(User::factory()->create())
        ->postJson(route('game.visit.store'), ['kind' => 'start', 'track' => 'spa', 'device' => 'desktop'])
        ->assertForbidden();

    expect(GameVisit::query()->where('kind', GameVisit::KIND_START)->count())->toBe(1);
});

it('връща 404 при изключена игра', function () {
    config(['features.game' => false]);

    $this->postJson(route('game.visit.store'), ['kind' => 'view', 'device' => 'mobile'])->assertNotFound();
});

it('изтрива посещенията след година', function () {
    GameVisit::factory()->create(['created_at' => now()->subDays(366)]);
    GameVisit::factory()->create(['created_at' => now()->subDays(30)]);

    $this->artisan('model:prune', ['--model' => [GameVisit::class]])->assertSuccessful();

    expect(GameVisit::query()->count())->toBe(1);
});

it('обобщава посетителите за деня, седмицата и месеца — гости и регистрирани', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    // Двама гости днес (единият отваря два пъти), един регистриран, един гост-опит.
    GameVisit::factory()->create(['visitor_key' => str_repeat('a', 64)]);
    GameVisit::factory()->mobile()->create(['visitor_key' => str_repeat('a', 64)]);
    GameVisit::factory()->create(['visitor_key' => str_repeat('b', 64)]);
    GameVisit::factory()->create(['visitor_key' => str_repeat('c', 64), 'user_id' => User::factory()->create()->id]);
    GameVisit::factory()->start()->create(['visitor_key' => str_repeat('b', 64)]);
    GameSession::factory()->create();
    // Преди 10 дни: влиза само в 30-дневния прозорец.
    GameVisit::factory()->create(['visitor_key' => str_repeat('d', 64), 'created_at' => now()->subDays(10)]);

    $widget = Livewire::test(GameVisitsOverview::class)->assertOk();
    $method = new ReflectionMethod(GameVisitsOverview::class, 'getStats');
    $stats = collect($method->invoke($widget->instance()))->keyBy(fn ($stat): string => $stat->getLabel());

    expect($stats['Днес: посетители']->getValue())->toBe('3 (2 гости · 1 регистрирани)')
        ->and($stats['7 дни: посетители']->getValue())->toBe('3 (2 гости · 1 регистрирани)')
        ->and($stats['30 дни: посетители']->getValue())->toBe('4 (3 гости · 1 регистрирани)');
    $widget->assertSee('4 отваряния, 25% от телефон')
        ->assertSee('опити: 1 гости · 1 регистрирани (1 души)');
});
