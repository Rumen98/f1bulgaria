<?php

declare(strict_types=1);

use App\Enums\WouldRecommend;
use App\Models\SurveyResponse;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('гост се праща към login за страницата', function () {
    $this->get(route('feedback'))->assertRedirect(route('login'));
});

it('логнат потребител вижда страницата', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('feedback'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Feedback/Index'));
});

it('гост не може да изпрати анкетата', function () {
    $this->post(route('feedback.store'), [
        'rating' => 5,
        'would_recommend' => 'yes',
        'source' => 'page',
    ])->assertRedirect(route('login'));

    expect(SurveyResponse::count())->toBe(0);
});

it('записва изпратена анкета', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('feedback.store'), [
            'rating' => 4,
            'would_recommend' => 'maybe',
            'comment' => 'Искам live timing.',
            'source' => 'prompt',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $response = SurveyResponse::first();
    expect($response)->not->toBeNull()
        ->and($response->user_id)->toBe($user->id)
        ->and($response->rating)->toBe(4)
        ->and($response->would_recommend)->toBe(WouldRecommend::Maybe)
        ->and($response->comment)->toBe('Искам live timing.')
        ->and($response->source)->toBe('prompt')
        ->and($response->submitted_at)->not->toBeNull()
        ->and($response->dismissed_at)->toBeNull();
});

it('отхвърля невалидни данни', function (array $payload, string $errorField) {
    $user = User::factory()->create();

    $this->actingAs($user)->from(route('feedback'))
        ->post(route('feedback.store'), $payload)
        ->assertSessionHasErrors($errorField);

    expect(SurveyResponse::count())->toBe(0);
})->with([
    'липсваща оценка' => [['would_recommend' => 'yes', 'source' => 'page'], 'rating'],
    'оценка извън скалата' => [['rating' => 6, 'would_recommend' => 'yes', 'source' => 'page'], 'rating'],
    'невалидна препоръка' => [['rating' => 3, 'would_recommend' => 'dunno', 'source' => 'page'], 'would_recommend'],
    'твърде дълъг коментар' => [['rating' => 3, 'would_recommend' => 'no', 'comment' => str_repeat('а', 2001), 'source' => 'page'], 'comment'],
    'невалиден източник' => [['rating' => 3, 'would_recommend' => 'no', 'source' => 'email'], 'source'],
]);

it('записва скриване на картата без отговори', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('feedback.dismiss'))
        ->assertRedirect();

    $response = SurveyResponse::first();
    expect($response)->not->toBeNull()
        ->and($response->user_id)->toBe($user->id)
        ->and($response->rating)->toBeNull()
        ->and($response->submitted_at)->toBeNull()
        ->and($response->dismissed_at)->not->toBeNull();
});

it('след изпращане картата спира да се показва', function () {
    $user = User::factory()->create(['created_at' => now()->subMonth()]);

    $this->actingAs($user)->post(route('feedback.store'), [
        'rating' => 5,
        'would_recommend' => 'yes',
        'source' => 'prompt',
    ]);

    $this->actingAs($user)->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('survey.shouldPrompt', false));
});

it('ограничава честотата на изпращане (throttle)', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $i) {
        $this->actingAs($user)->post(route('feedback.store'), [
            'rating' => 3,
            'would_recommend' => 'yes',
            'source' => 'page',
        ]);
    }

    $this->actingAs($user)
        ->post(route('feedback.store'), ['rating' => 3, 'would_recommend' => 'yes', 'source' => 'page'])
        ->assertStatus(429);
});

it('изчерпан throttle на формата не блокира скриването на картата', function () {
    // Регресия: голият 'throttle:5,1' ползва една кофа на потребител —
    // без изричните префикси store и dismiss се блокираха взаимно.
    $user = User::factory()->create();

    foreach (range(1, 5) as $i) {
        $this->actingAs($user)->post(route('feedback.store'), [
            'rating' => 3,
            'would_recommend' => 'yes',
            'source' => 'page',
        ]);
    }

    $this->actingAs($user)->post(route('feedback.dismiss'))->assertRedirect();
});
