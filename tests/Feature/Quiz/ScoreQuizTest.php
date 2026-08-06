<?php

declare(strict_types=1);

use App\Models\QuizQuestion;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['features.quiz' => true]);
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
