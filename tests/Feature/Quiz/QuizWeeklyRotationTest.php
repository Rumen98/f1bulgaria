<?php

declare(strict_types=1);

use App\Models\QuizQuestion;
use App\Models\User;
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

it('скрива покорените въпроси от седмичния набор', function () {
    QuizQuestion::factory()->count(25)->create();
    Carbon::setTestNow(Carbon::parse('2026-09-01 10:00', 'Europe/Sofia'));

    $user = User::factory()->create();
    $weeklyIds = quizIds($this);

    // Покорява първите 3 от седмичния набор с верните отговори.
    $answers = collect($weeklyIds)->take(3)
        ->map(fn (int $id) => ['id' => $id, 'choice' => QuizQuestion::query()->find($id)->correct_option])
        ->values()->all();
    $this->actingAs($user)->post('/quiz', ['answers' => $answers])->assertOk();

    $response = $this->actingAs($user)->get('/quiz')->assertOk();
    $props = $response->viewData('page')['props'];
    $shown = collect($props['questions'])->pluck('id')->all();

    expect($shown)->toHaveCount(7)
        ->and(array_intersect($shown, array_slice($weeklyIds, 0, 3)))->toBeEmpty()
        ->and($props['weeklyAnswered'])->toBe(3)
        ->and($props['weeklyTotal'])->toBe(10);
});

it('решил всичко за седмицата получава празен списък и пълен брояч', function () {
    QuizQuestion::factory()->count(12)->create();
    Carbon::setTestNow(Carbon::parse('2026-09-01 10:00', 'Europe/Sofia'));

    $user = User::factory()->create();
    $weeklyIds = quizIds($this);

    $answers = collect($weeklyIds)
        ->map(fn (int $id) => ['id' => $id, 'choice' => QuizQuestion::query()->find($id)->correct_option])
        ->values()->all();
    $this->actingAs($user)->post('/quiz', ['answers' => $answers])->assertOk();

    $props = $this->actingAs($user)->get('/quiz')->assertOk()->viewData('page')['props'];

    expect($props['questions'])->toBeEmpty()
        ->and($props['weeklyAnswered'])->toBe($props['weeklyTotal']);
});

it('гостът вижда пълния набор', function () {
    QuizQuestion::factory()->count(25)->create();
    Carbon::setTestNow(Carbon::parse('2026-09-01 10:00', 'Europe/Sofia'));

    expect(quizIds($this))->toHaveCount(10);
});

it('грешно отговорен въпрос също изчезва до понеделник', function () {
    QuizQuestion::factory()->count(12)->create();
    Carbon::setTestNow(Carbon::parse('2026-09-01 10:00', 'Europe/Sofia'));

    $user = User::factory()->create();
    $weeklyIds = quizIds($this);
    $first = QuizQuestion::query()->find($weeklyIds[0]);

    // Грешен отговор: верният + 1 в кръг 1..4.
    $wrong = ($first->correct_option % 4) + 1;
    $this->actingAs($user)
        ->post('/quiz', ['answers' => [['id' => $first->id, 'choice' => $wrong]]])
        ->assertOk();

    $props = $this->actingAs($user)->get('/quiz')->assertOk()->viewData('page')['props'];

    expect(collect($props['questions'])->pluck('id')->all())->not->toContain($first->id)
        ->and($props['weeklyAnswered'])->toBe(1)
        ->and($props['weeklyPoints'])->toBe(0);
});

it('поправка в същата седмица не носи точка — прегледът разкри отговора', function () {
    QuizQuestion::factory()->count(12)->create();
    Carbon::setTestNow(Carbon::parse('2026-09-01 10:00', 'Europe/Sofia'));

    $user = User::factory()->create();
    $first = QuizQuestion::query()->find(quizIds($this)[0]);
    $wrong = ($first->correct_option % 4) + 1;

    $this->actingAs($user)->post('/quiz', ['answers' => [['id' => $first->id, 'choice' => $wrong]]]);
    $this->actingAs($user)->post('/quiz', ['answers' => [['id' => $first->id, 'choice' => $first->correct_option]]]);

    expect($user->fresh()->masteredQuizQuestions()->count())->toBe(0);
});

it('сгрешен миналата седмица получава нов опит и точка тази', function () {
    QuizQuestion::factory()->count(5)->create(); // наборът е едни и същи 5 всяка седмица
    config(['quiz.count' => 5]);

    Carbon::setTestNow(Carbon::parse('2026-08-25 10:00', 'Europe/Sofia')); // седмица 35
    $user = User::factory()->create();
    $first = QuizQuestion::query()->find(quizIds($this)[0]);
    $wrong = ($first->correct_option % 4) + 1;
    $this->actingAs($user)->post('/quiz', ['answers' => [['id' => $first->id, 'choice' => $wrong]]]);

    Carbon::setTestNow(Carbon::parse('2026-09-01 10:00', 'Europe/Sofia')); // седмица 36

    // Въпросът отново е на екрана…
    $props = $this->actingAs($user)->get('/quiz')->assertOk()->viewData('page')['props'];
    expect(collect($props['questions'])->pluck('id')->all())->toContain($first->id);

    // …и верният отговор вече носи точката.
    $this->actingAs($user)->post('/quiz', ['answers' => [['id' => $first->id, 'choice' => $first->correct_option]]]);
    expect($user->fresh()->masteredQuizQuestions()->count())->toBe(1);
});
