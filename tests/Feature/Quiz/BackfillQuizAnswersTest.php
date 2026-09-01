<?php

declare(strict_types=1);

use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Services\Quiz\QuizProgressService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['features.quiz' => true, 'quiz.count' => 10]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Историческо състояние отпреди answered_at: предаване с 4 верни от 10 —
 * ред има само за верните, грешните са без следа.
 */
function legacySubmission(User $user): array
{
    QuizQuestion::factory()->count(25)->create();
    Carbon::setTestNow(Carbon::parse('2026-09-01 10:00', 'Europe/Sofia'));

    $setIds = app(QuizProgressService::class)->weeklyQuestions()->pluck('id')->all();

    QuizAttempt::create(['user_id' => $user->id, 'score' => 4, 'total' => 10]);

    foreach (array_slice($setIds, 0, 4) as $id) {
        DB::table('quiz_question_user')->insert([
            'user_id' => $user->id,
            'quiz_question_id' => $id,
            'answered_at' => now(),
            'first_correct_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $setIds;
}

it('маркира сгрешените въпроси от минало предаване като отговорени', function () {
    $user = User::factory()->create();
    $setIds = legacySubmission($user);

    $this->artisan('padok:backfill-quiz-answers')->assertSuccessful();

    // Всичките 10 от набора вече имат ред; 6-те наваксани са без точка.
    expect($user->answeredQuizQuestions()->count())->toBe(10)
        ->and($user->masteredQuizQuestions()->count())->toBe(4);

    // И екранът вече не ги показва.
    $props = $this->actingAs($user)->get('/quiz')->assertOk()->viewData('page')['props'];
    expect($props['questions'])->toBeEmpty()
        ->and($props['weeklyAnswered'])->toBe(10)
        ->and($props['weeklyPoints'])->toBe(4);
});

it('повторен пуск не дублира', function () {
    $user = User::factory()->create();
    legacySubmission($user);

    $this->artisan('padok:backfill-quiz-answers')->assertSuccessful();
    $this->artisan('padok:backfill-quiz-answers')->assertSuccessful();

    expect($user->answeredQuizQuestions()->count())->toBe(10);
});

it('dry-run не пише нищо', function () {
    $user = User::factory()->create();
    legacySubmission($user);

    $this->artisan('padok:backfill-quiz-answers', ['--dry-run' => true])->assertSuccessful();

    expect($user->answeredQuizQuestions()->count())->toBe(4);
});

it('не пипа потребители без предавания', function () {
    $player = User::factory()->create();
    $spectator = User::factory()->create();
    legacySubmission($player);

    $this->artisan('padok:backfill-quiz-answers')->assertSuccessful();

    expect($spectator->answeredQuizQuestions()->count())->toBe(0);
});
