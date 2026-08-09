<?php

declare(strict_types=1);

use App\Models\QuizQuestion;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['features.quiz' => true]);
});

it('връща 404 когато флагът е изключен', function () {
    config(['features.quiz' => false]);

    $this->post(route('quiz.score'), [
        'answers' => [['id' => 1, 'choice' => 1]],
    ])->assertNotFound();
});

it('оценява отговорите сървърно', function () {
    $q1 = QuizQuestion::factory()->correct(1)->create();
    $q2 = QuizQuestion::factory()->correct(2)->create();
    $q3 = QuizQuestion::factory()->correct(3)->create();

    $this->post(route('quiz.score'), [
        'answers' => [
            ['id' => $q1->id, 'choice' => 1], // вярно
            ['id' => $q2->id, 'choice' => 2], // вярно
            ['id' => $q3->id, 'choice' => 1], // грешно
        ],
    ])->assertInertia(fn (Assert $page) => $page
        ->component('Quiz/Index')
        ->where('result.score', 2)
        ->where('result.total', 3)
        ->has('result.review', 3)
    );
});

it('разкрива верния отговор само в ревюто след submit', function () {
    $q = QuizQuestion::factory()->correct(3)->create();

    $this->post(route('quiz.score'), [
        'answers' => [['id' => $q->id, 'choice' => 3]],
    ])->assertInertia(fn (Assert $page) => $page
        ->where('result.review.0.correct_option', 3)
        ->where('result.review.0.is_correct', true)
    );
});

it('подправен избор не променя резултата', function () {
    $q = QuizQuestion::factory()->correct(2)->create();

    // клиентът праща грешен избор — сървърът пази верния в базата
    $this->post(route('quiz.score'), [
        'answers' => [['id' => $q->id, 'choice' => 4]],
    ])->assertInertia(fn (Assert $page) => $page->where('result.score', 0));
});

it('игнорира непознат/деактивиран въпрос', function () {
    $active = QuizQuestion::factory()->correct(1)->create();
    $inactive = QuizQuestion::factory()->inactive()->create();

    $this->post(route('quiz.score'), [
        'answers' => [
            ['id' => $active->id, 'choice' => 1],
            ['id' => $inactive->id, 'choice' => 1],
        ],
    ])->assertInertia(fn (Assert $page) => $page
        ->where('result.total', 1)
        ->has('result.review', 1)
    );
});

it('брои пропуснат въпрос като грешен', function () {
    $q = QuizQuestion::factory()->correct(2)->create();

    $this->post(route('quiz.score'), [
        'answers' => [['id' => $q->id, 'choice' => null]],
    ])->assertInertia(fn (Assert $page) => $page
        ->where('result.score', 0)
        ->where('result.review.0.chosen_option', null)
        ->where('result.review.0.is_correct', false)
    );
});

it('пренасочва към куиза когато всички id-та са непознати', function () {
    $this->post(route('quiz.score'), [
        'answers' => [['id' => 999999, 'choice' => 1]],
    ])->assertRedirect(route('quiz'));
});

it('отхвърля дублирани id-та', function () {
    $q = QuizQuestion::factory()->correct(1)->create();

    $this->from(route('quiz'))
        ->post(route('quiz.score'), [
            'answers' => [
                ['id' => $q->id, 'choice' => 1],
                ['id' => $q->id, 'choice' => 2],
            ],
        ])
        ->assertSessionHasErrors(['answers.0.id', 'answers.1.id']);
});

it('отхвърля повече от 50 отговора', function () {
    $answers = collect(range(1, 51))
        ->map(fn (int $i) => ['id' => $i, 'choice' => 1])
        ->all();

    $this->from(route('quiz'))
        ->post(route('quiz.score'), ['answers' => $answers])
        ->assertSessionHasErrors('answers');
});

it('изисква поне един отговор', function () {
    $this->from(route('quiz'))
        ->post(route('quiz.score'), ['answers' => []])
        ->assertSessionHasErrors('answers');
});

it('валидира диапазона на избора', function () {
    $q = QuizQuestion::factory()->create();

    $this->from(route('quiz'))
        ->post(route('quiz.score'), [
            'answers' => [['id' => $q->id, 'choice' => 9]],
        ])
        ->assertSessionHasErrors('answers.0.choice');
});

it('не записва нищо в базата', function () {
    $q = QuizQuestion::factory()->correct(1)->create();
    $before = QuizQuestion::count();

    $this->post(route('quiz.score'), [
        'answers' => [['id' => $q->id, 'choice' => 1]],
    ]);

    expect(QuizQuestion::count())->toBe($before);
});
