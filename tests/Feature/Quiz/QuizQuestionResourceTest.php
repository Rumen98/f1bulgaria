<?php

declare(strict_types=1);

use App\Filament\Resources\QuizQuestions\Pages\CreateQuizQuestion;
use App\Filament\Resources\QuizQuestions\Pages\EditQuizQuestion;
use App\Filament\Resources\QuizQuestions\QuizQuestionResource;
use App\Models\QuizQuestion;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
});

it('админ отваря списъка с въпроси', function () {
    $this->get(QuizQuestionResource::getUrl('index'))->assertOk();
});

it('админ отваря формата за създаване', function () {
    $this->get(QuizQuestionResource::getUrl('create'))->assertOk();
});

it('създава въпрос през админ формата', function () {
    Livewire::test(CreateQuizQuestion::class)
        ->fillForm([
            'question' => 'Кой е първият българин с точки във Формула 2?',
            'option_1' => 'Никола Цолов',
            'option_2' => 'Пламен Кралев',
            'option_3' => 'Владимир Арабаджиев',
            'option_4' => 'Цветан Цветков',
            'correct_option' => 1,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(QuizQuestion::where('question', 'Кой е първият българин с точки във Формула 2?')
        ->where('correct_option', 1)
        ->where('is_active', true)
        ->exists())->toBeTrue();
});

it('редактира въпрос през админ формата', function () {
    $question = QuizQuestion::factory()->correct(1)->create();

    Livewire::test(EditQuizQuestion::class, ['record' => $question->getRouteKey()])
        ->fillForm([
            'question' => 'Обновен въпрос?',
            'correct_option' => 3,
            'is_active' => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $question->refresh();

    expect($question->question)->toBe('Обновен въпрос?')
        ->and($question->correct_option)->toBe(3)
        ->and($question->is_active)->toBeFalse();
});

it('edit формата пре-попълва верния отговор въпреки $hidden', function () {
    $question = QuizQuestion::factory()->correct(2)->create();

    Livewire::test(EditQuizQuestion::class, ['record' => $question->getRouteKey()])
        ->assertFormSet(['correct_option' => 2]);
});

it('не-админ получава 403 за списъка с въпроси', function () {
    $this->actingAs(User::factory()->create(['is_admin' => false]));

    $this->get(QuizQuestionResource::getUrl('index'))->assertForbidden();
});
