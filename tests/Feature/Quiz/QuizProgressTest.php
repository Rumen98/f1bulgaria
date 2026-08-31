<?php

declare(strict_types=1);

use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['features.quiz' => true]);
});

/**
 * Отговори за подадените въпроси — $correct контролира колко от тях са верни.
 *
 * @return array<int, array{id:int, choice:int}>
 */
function quizAnswers(iterable $questions, int $correct = PHP_INT_MAX): array
{
    $answers = [];

    foreach ($questions as $i => $question) {
        $answers[] = [
            'id' => $question->id,
            // Грешният избор е „верният + 1" в кръг 1..4.
            'choice' => $i < $correct
                ? $question->correct_option
                : ($question->correct_option % 4) + 1,
        ];
    }

    return $answers;
}

it('записва опит и точки за влязъл потребител', function () {
    $user = User::factory()->create();
    $questions = QuizQuestion::factory()->count(3)->create();

    $this->actingAs($user)
        ->post('/quiz', ['answers' => quizAnswers($questions, correct: 2)])
        ->assertOk();

    expect(QuizAttempt::query()->where('user_id', $user->id)->count())->toBe(1);
    expect($user->fresh()->masteredQuizQuestions()->count())->toBe(2);
});

it('не записва нищо за анонимен играч', function () {
    $questions = QuizQuestion::factory()->count(3)->create();

    $this->post('/quiz', ['answers' => quizAnswers($questions)])->assertOk();

    expect(QuizAttempt::query()->count())->toBe(0);
});

it('дава точка само веднъж на въпрос при повторно изиграване', function () {
    $user = User::factory()->create();
    $questions = QuizQuestion::factory()->count(3)->create();
    $answers = quizAnswers($questions);

    $this->actingAs($user)->post('/quiz', ['answers' => $answers])->assertOk();
    $this->actingAs($user)->post('/quiz', ['answers' => $answers])->assertOk();

    // Два опита в историята, но точките остават 3 — това е анти-фарм гаранцията.
    expect(QuizAttempt::query()->where('user_id', $user->id)->count())->toBe(2);
    expect($user->fresh()->masteredQuizQuestions()->count())->toBe(3);
});

it('отчита новите точки от текущия кръг', function () {
    $user = User::factory()->create();
    $questions = QuizQuestion::factory()->count(2)->create();

    $this->actingAs($user)
        ->post('/quiz', ['answers' => quizAnswers($questions)])
        ->assertInertia(fn (Assert $page) => $page->where('result.new_points', 2));
});

it('показва точките и тавана на страницата на куиза', function () {
    $user = User::factory()->create();
    $questions = QuizQuestion::factory()->count(5)->create();

    $this->actingAs($user)->post('/quiz', ['answers' => quizAnswers($questions, correct: 3)]);

    $this->actingAs($user)->get('/quiz')
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.points', 3)
            ->where('stats.available', 5)
            ->where('stats.attempts', 1)
            ->where('stats.best_score', 3)
        );
});

it('не брои точки от деактивирани въпроси', function () {
    $user = User::factory()->create();
    $questions = QuizQuestion::factory()->count(2)->create();

    $this->actingAs($user)->post('/quiz', ['answers' => quizAnswers($questions)]);

    $questions->first()->update(['is_active' => false]);

    $this->actingAs($user)->get('/quiz')
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.points', 1)
            ->where('stats.available', 1)
        );
});

it('подрежда класацията по точки и я показва публично', function () {
    $leader = User::factory()->create(['name' => 'Лидер']);
    $second = User::factory()->create(['name' => 'Втори']);
    $questions = QuizQuestion::factory()->count(3)->create();

    $this->actingAs($leader)->post('/quiz', ['answers' => quizAnswers($questions, correct: 3)]);
    $this->actingAs($second)->post('/quiz', ['answers' => quizAnswers($questions, correct: 1)]);

    $this->get('/quiz')
        ->assertInertia(fn (Assert $page) => $page
            ->where('leaderboard.0.name', 'Лидер')
            ->where('leaderboard.0.points', 3)
            ->where('leaderboard.1.name', 'Втори')
            ->where('leaderboard.1.points', 1)
        );
});

it('изключва банати потребители от класацията', function () {
    $banned = User::factory()->create(['banned_at' => now()]);
    $questions = QuizQuestion::factory()->count(2)->create();

    $this->actingAs($banned)->post('/quiz', ['answers' => quizAnswers($questions)]);

    $this->get('/quiz')
        ->assertInertia(fn (Assert $page) => $page->has('leaderboard', 0));
});
