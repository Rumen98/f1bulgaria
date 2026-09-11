<?php

declare(strict_types=1);

use App\Models\GameSession;
use App\Models\User;

beforeEach(function () {
    config(['features.game' => true]);
});

it('записва „Карай" на регистриран потребител', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('game.session.store'), ['track' => 'monza', 'device' => 'mobile', 'mode' => 'race'])
        ->assertNoContent();

    $session = GameSession::query()->sole();

    expect($session->user_id)->toBe($user->id)
        ->and($session->track_slug)->toBe('monza')
        ->and($session->device)->toBe(GameSession::DEVICE_MOBILE)
        ->and($session->mode)->toBe(GameSession::MODE_RACE);
});

it('не записва гости', function () {
    $this->postJson(route('game.session.store'), ['track' => 'monza', 'device' => 'desktop', 'mode' => 'solo'])
        ->assertUnauthorized();

    expect(GameSession::query()->count())->toBe(0);
});

it('отхвърля непозната писта, устройство и режим', function (array $payload, string $field) {
    $this->actingAs(User::factory()->create())
        ->postJson(route('game.session.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(GameSession::query()->count())->toBe(0);
})->with([
    'писта' => [['track' => 'nordschleife', 'device' => 'desktop', 'mode' => 'solo'], 'track'],
    'устройство' => [['track' => 'monza', 'device' => 'console', 'mode' => 'solo'], 'device'],
    'режим' => [['track' => 'monza', 'device' => 'desktop', 'mode' => 'drift'], 'mode'],
]);

it('връща 404 при изключена игра', function () {
    config(['features.game' => false]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('game.session.store'), ['track' => 'monza', 'device' => 'desktop', 'mode' => 'solo'])
        ->assertNotFound();
});

it('изчезва заедно с изтрития потребител', function () {
    $session = GameSession::factory()->create();

    $session->user->delete();

    expect(GameSession::query()->count())->toBe(0);
});
