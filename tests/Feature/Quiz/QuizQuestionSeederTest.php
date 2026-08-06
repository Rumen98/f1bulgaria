<?php

declare(strict_types=1);

use App\Models\QuizQuestion;
use Database\Seeders\QuizQuestionSeeder;

it('засява въпроси идемпотентно', function () {
    $this->seed(QuizQuestionSeeder::class);
    $first = QuizQuestion::count();

    $this->seed(QuizQuestionSeeder::class);

    expect(QuizQuestion::count())->toBe($first)
        ->and($first)->toBeGreaterThanOrEqual(25);

    QuizQuestion::all()->each(function (QuizQuestion $q) {
        expect($q->correct_option)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(4);
    });
});
