<?php

declare(strict_types=1);

use App\Models\QuizQuestion;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['features.quiz' => true]);
});

it('връща 404 когато флагът е изключен', function () {
    config(['features.quiz' => false]);

    $this->get('/quiz')->assertNotFound();
});

it('показва N случайни активни въпроса', function () {
    QuizQuestion::factory()->count(14)->create();
    config(['quiz.count' => 10]);

    $this->get('/quiz')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Quiz/Index')
            ->has('questions', 10)
            ->where('result', null)
        );
});

it('не изпраща верния отговор на клиента', function () {
    QuizQuestion::factory()->create();

    $this->get('/quiz')
        ->assertInertia(fn (Assert $page) => $page
            ->has('questions.0', fn (Assert $q) => $q
                ->has('id')
                ->has('question')
                ->has('options', 4)
                ->missing('correct_option')
            )
        );
});

it('избира само активни въпроси', function () {
    QuizQuestion::factory()->count(3)->create();
    QuizQuestion::factory()->count(3)->inactive()->create();

    $this->get('/quiz')
        ->assertInertia(fn (Assert $page) => $page->has('questions', 3));
});

it('показва празно състояние без активни въпроси', function () {
    QuizQuestion::factory()->count(3)->inactive()->create();

    $this->get('/quiz')
        ->assertInertia(fn (Assert $page) => $page->has('questions', 0));
});
