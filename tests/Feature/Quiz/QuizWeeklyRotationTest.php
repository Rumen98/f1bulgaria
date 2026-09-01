<?php

declare(strict_types=1);

use App\Models\QuizQuestion;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['features.quiz' => true, 'quiz.count' => 10]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** @return array<int, int> id-тата от отговора на /quiz */
function quizIds($testCase): array
{
    $response = $testCase->get('/quiz')->assertOk();

    return collect($response->viewData('page')['props']['questions'])->pluck('id')->all();
}

it('дава един и същ набор през цялата седмица', function () {
    QuizQuestion::factory()->count(25)->create();
    Carbon::setTestNow(Carbon::parse('2026-09-01 10:00', 'Europe/Sofia'));

    $monday = quizIds($this);

    Carbon::setTestNow(Carbon::parse('2026-09-06 22:00', 'Europe/Sofia')); // неделя същата седмица
    $sunday = quizIds($this);

    expect($sunday)->toBe($monday)->and($monday)->toHaveCount(10);
});

it('сменя набора в понеделник', function () {
    QuizQuestion::factory()->count(25)->create();

    Carbon::setTestNow(Carbon::parse('2026-09-06 22:00', 'Europe/Sofia')); // неделя, седмица 36
    $thisWeek = quizIds($this);

    Carbon::setTestNow(Carbon::parse('2026-09-07 08:00', 'Europe/Sofia')); // понеделник, седмица 37
    $nextWeek = quizIds($this);

    // Подредбата е по md5(id|седмица) — вероятността два различни седмични
    // ключа да дадат същите 10 в същия ред е практически нулева.
    expect($nextWeek)->not->toBe($thisWeek);
});

it('подава номера на седмицата към страницата', function () {
    QuizQuestion::factory()->count(3)->create();
    Carbon::setTestNow(Carbon::parse('2026-09-01 10:00', 'Europe/Sofia'));

    $this->get('/quiz')
        ->assertInertia(fn (Assert $page) => $page->where('week', 36));
});
