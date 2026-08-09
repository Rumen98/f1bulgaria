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

it('обновява редактиран текст при re-seed без да създава дубликат', function () {
    $this->seed(QuizQuestionSeeder::class);
    $count = QuizQuestion::count();

    $editedSeeder = new class extends QuizQuestionSeeder
    {
        protected function questions(): array
        {
            $questions = parent::questions();
            $questions[0]['question'] = 'Кой пилот има най-много победи в Гран При във Формула 1?';

            return $questions;
        }
    };
    $editedSeeder->run();

    expect(QuizQuestion::count())->toBe($count)
        ->and(QuizQuestion::query()->where('code', 'most-wins')->count())->toBe(1)
        ->and(QuizQuestion::query()->firstWhere('code', 'most-wins')->question)
        ->toBe('Кой пилот има най-много победи в Гран При във Формула 1?');
});

it('деактивация от админа преживява re-seed', function () {
    $this->seed(QuizQuestionSeeder::class);

    QuizQuestion::query()->where('code', 'most-wins')->update(['is_active' => false]);

    $this->seed(QuizQuestionSeeder::class);

    expect(QuizQuestion::query()->firstWhere('code', 'most-wins')->is_active)->toBeFalse();
});
