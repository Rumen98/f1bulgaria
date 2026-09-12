<?php

declare(strict_types=1);

use App\Models\GameFeedback;
use App\Models\GameLapRecord;
use App\Models\GameSession;
use App\Models\SurveyResponse;
use App\Models\User;
use App\Services\Game\GameFeedbackService;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['features.game' => true]);
});

it('записва отделно мнение само за играта към собствено каране', function () {
    $session = GameSession::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($session->user)->postJson(route('game.feedback.store'), [
        'session_id' => $session->id,
        'rating' => 3,
        'controls_rating' => 2,
        'performance_rating' => 4,
        'difficulty' => 'hard',
        'reason' => 'controls',
        'comment' => 'На телефона ми е трудно да завивам.',
        'would_play_again' => 'maybe',
        'user_id' => $other->id,
        'submission_key' => 'forged',
    ])->assertCreated()->assertJsonPath('message', 'Благодарим! Мнението ти ще помогне да подобрим играта.');

    $feedback = GameFeedback::query()->sole();

    expect($feedback->user_id)->toBe($session->user_id)
        ->and($feedback->session_id)->toBe($session->id)
        ->and($feedback->submission_key)->toBe("session:{$session->id}")
        ->and($feedback->rating)->toBe(3)
        ->and($feedback->controls_rating)->toBe(2)
        ->and($feedback->performance_rating)->toBe(4)
        ->and($feedback->difficulty)->toBe('hard')
        ->and($feedback->reason)->toBe('controls')
        ->and($feedback->comment)->toBe('На телефона ми е трудно да завивам.')
        ->and($feedback->would_play_again)->toBe('maybe')
        ->and($feedback->session->is($session))->toBeTrue()
        ->and(SurveyResponse::query()->count())->toBe(0);
});

it('позволява само оценка от играч със стара обиколка без сесия', function () {
    $lap = GameLapRecord::factory()->create();

    $this->actingAs($lap->user)->postJson(route('game.feedback.store'), ['rating' => 5])->assertCreated();

    $feedback = GameFeedback::query()->sole();

    expect($feedback->session_id)->toBeNull()
        ->and($feedback->submission_key)->toBe('legacy')
        ->and($feedback->rating)->toBe(5)
        ->and($feedback->controls_rating)->toBeNull()
        ->and($feedback->comment)->toBeNull();
});

it('не свързва мнение без сесия със случайно последно каране', function () {
    $session = GameSession::factory()->create();

    $this->actingAs($session->user)->postJson(route('game.feedback.store'), [
        'session_id' => null, 'rating' => 4,
    ])->assertCreated();

    expect(GameFeedback::query()->sole()->session_id)->toBeNull();
});

it('обновява повторното изпращане без да дублира оценките', function (?string $scope) {
    $session = GameSession::factory()->create();
    $sessionId = $scope === 'session' ? $session->id : null;

    $this->actingAs($session->user)->postJson(route('game.feedback.store'), [
        'session_id' => $sessionId, 'rating' => 2, 'comment' => 'Първо мнение',
    ])->assertCreated();

    $this->postJson(route('game.feedback.store'), [
        'session_id' => $sessionId, 'rating' => 4, 'comment' => null,
    ])->assertCreated();

    expect(GameFeedback::query()->count())->toBe(1)
        ->and(GameFeedback::query()->sole()->rating)->toBe(4)
        ->and(GameFeedback::query()->sole()->comment)->toBeNull();
})->with(['сесия' => ['session'], 'общо мнение' => [null]]);

it('запазва отделни мнения за различни карания', function () {
    $user = User::factory()->create();
    $sessions = GameSession::factory()->for($user)->count(2)->create();

    foreach ($sessions as $session) {
        $this->actingAs($user)->postJson(route('game.feedback.store'), [
            'session_id' => $session->id, 'rating' => 4,
        ])->assertCreated();
    }

    expect(GameFeedback::query()->count())->toBe(2);
});

it('не допуска гости', function () {
    $this->postJson(route('game.feedback.store'), ['rating' => 4])->assertUnauthorized();

    expect(GameFeedback::query()->count())->toBe(0);
});

it('не допуска потребител който не е играл дори с чужда сесия', function () {
    $session = GameSession::factory()->create();

    $this->actingAs(User::factory()->create())->postJson(route('game.feedback.store'), [
        'session_id' => $session->id, 'rating' => 4,
    ])->assertForbidden();

    expect(GameFeedback::query()->count())->toBe(0);
});

it('не допуска блокиран играч', function () {
    $session = GameSession::factory()->for(User::factory()->state(['banned_at' => now()]))->create();

    $this->actingAs($session->user)->postJson(route('game.feedback.store'), ['rating' => 4])->assertForbidden();

    expect(GameFeedback::query()->count())->toBe(0);
});

it('отхвърля чужда сесия от потребител който иначе е играл', function () {
    $session = GameSession::factory()->create();
    $other = GameSession::factory()->create();

    $this->actingAs($session->user)->postJson(route('game.feedback.store'), [
        'session_id' => $other->id, 'rating' => 4,
    ])->assertUnprocessable()->assertJsonValidationErrors('session_id');

    expect(GameFeedback::query()->count())->toBe(0);
});

it('проверява задължителната оценка и ограничените отговори', function (array $answers, string $field) {
    $session = GameSession::factory()->create();

    $this->actingAs($session->user)->postJson(route('game.feedback.store'), $answers)
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(GameFeedback::query()->count())->toBe(0);
})->with([
    'липсва оценка' => [[], 'rating'],
    'ниска оценка' => [['rating' => 0], 'rating'],
    'висока оценка' => [['rating' => 6], 'rating'],
    'дробна оценка' => [['rating' => 3.5], 'rating'],
    'оценка за управление' => [['rating' => 4, 'controls_rating' => 6], 'controls_rating'],
    'оценка за плавност' => [['rating' => 4, 'performance_rating' => 0], 'performance_rating'],
    'непозната трудност' => [['rating' => 4, 'difficulty' => 'impossible'], 'difficulty'],
    'непозната причина' => [['rating' => 4, 'reason' => 'anything'], 'reason'],
    'непознат отговор' => [['rating' => 4, 'would_play_again' => 'always'], 'would_play_again'],
    'дълъг текст' => [['rating' => 4, 'comment' => str_repeat('я', 2001)], 'comment'],
    'несъществуваща сесия' => [['rating' => 4, 'session_id' => 999999], 'session_id'],
    'отрицателна сесия' => [['rating' => 4, 'session_id' => -1], 'session_id'],
]);

it('скрива поканата от гости и хора които не са играли', function () {
    $feedback = app(GameFeedbackService::class);

    expect($feedback->prompt(null))->toBe(['eligible' => false, 'last_session_id' => null, 'submitted' => false])
        ->and($feedback->prompt(User::factory()->create()))->toBe(['eligible' => false, 'last_session_id' => null, 'submitted' => false]);
});

it('показва покана на старите играчи и я скрива след изпращане', function () {
    $lap = GameLapRecord::factory()->create();
    $service = app(GameFeedbackService::class);

    expect($service->prompt($lap->user))->toBe(['eligible' => true, 'last_session_id' => null, 'submitted' => false]);

    GameFeedback::factory()->for($lap->user)->create();

    expect($service->prompt($lap->user))->toBe(['eligible' => true, 'last_session_id' => null, 'submitted' => true]);
});

it('подава покана за последното собствено каране без чужди данни', function () {
    $session = GameSession::factory()->create();
    $latest = GameSession::factory()->for($session->user)->create();
    GameSession::factory()->create();

    $this->actingAs($session->user)->get(route('game'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Game/Index')
            ->where('gameFeedback', ['eligible' => true, 'last_session_id' => $latest->id, 'submitted' => false]));
});

it('поканата не се отваря сама, щом играчът е дал мнение веднъж — и за по-старо каране', function () {
    // Формата се отваря сама само до първото мнение; ново мнение за новото
    // каране остава възможно през бутона, но не врънкаме на всеки изход.
    $session = GameSession::factory()->create();
    GameFeedback::factory()->forSession($session)->create();
    $latest = GameSession::factory()->for($session->user)->create();

    expect(app(GameFeedbackService::class)->prompt($session->user))
        ->toBe(['eligible' => true, 'last_session_id' => $latest->id, 'submitted' => true]);
});

it('скрива поканата от блокиран играч независимо от историята', function () {
    $session = GameSession::factory()->for(User::factory()->state(['banned_at' => now()]))->create();

    expect(app(GameFeedbackService::class)->prompt($session->user))->toBe(['eligible' => false, 'last_session_id' => null, 'submitted' => false]);
});

it('изключва изпращането заедно с играта', function () {
    $session = GameSession::factory()->create();
    config(['features.game' => false]);

    $this->actingAs($session->user)->postJson(route('game.feedback.store'), ['rating' => 4])->assertNotFound();

    expect(GameFeedback::query()->count())->toBe(0);
});

it('ограничава честото изпращане без да натрупва дубликати', function () {
    $session = GameSession::factory()->create();
    $this->actingAs($session->user);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson(route('game.feedback.store'), ['rating' => 4])->assertCreated();
    }

    $this->postJson(route('game.feedback.store'), ['rating' => 4])->assertTooManyRequests();

    expect(GameFeedback::query()->count())->toBe(1);
});

it('запазва мнението при изтриване на сесия и го изтрива с акаунта', function () {
    $session = GameSession::factory()->create();
    $user = $session->user;
    $feedback = GameFeedback::factory()->forSession($session)->create();

    $session->delete();

    expect($feedback->fresh()->session_id)->toBeNull();

    $user->delete();

    expect(GameFeedback::query()->count())->toBe(0);
});
