<?php

declare(strict_types=1);

use App\Models\QuizQuestion;
use App\Services\News\Llm\LlmClient;
use App\Services\Quiz\QuizQuestionGenerator;

function draftResponse(array $questions): array
{
    return ['input' => ['questions' => $questions], 'input_tokens' => 10, 'output_tokens' => 20];
}

function verifierResponse(int $chosen, string $confidence = 'high', bool $ambiguous = false): array
{
    return [
        'input' => ['chosen_option' => $chosen, 'confidence' => $confidence, 'ambiguous' => $ambiguous],
        'input_tokens' => 5,
        'output_tokens' => 5,
    ];
}

function candidate(array $overrides = []): array
{
    return array_merge([
        'question' => 'През коя година Айртон Сена печели първата си титла?',
        'options' => ['1985', '1988', '1990', '1991'],
        'correct_option' => 2,
        'source_note' => 'Официалната статистика на ФИА за сезон 1988.',
    ], $overrides);
}

it('записва активен въпрос при единодушни проверители', function () {
    $llm = $this->mock(LlmClient::class);
    $llm->shouldReceive('completeWithTool')->once()
        ->withArgs(fn (...$args) => $args[2] === 'draft_quiz_questions')
        ->andReturn(draftResponse([candidate()]));
    $llm->shouldReceive('completeWithTool')->twice()
        ->withArgs(fn (...$args) => $args[2] === 'answer_quiz_question')
        ->andReturn(verifierResponse(2));

    $stats = app(QuizQuestionGenerator::class)->generate(1);

    $saved = QuizQuestion::query()->first();

    expect($stats['saved'])->toBe(1)
        ->and($saved->is_active)->toBeTrue()
        ->and($saved->correct_option)->toBe(2)
        ->and($saved->source_note)->toContain('ФИА');
});

it('отхвърля, когато проверител посочи друг отговор', function () {
    $llm = $this->mock(LlmClient::class);
    $llm->shouldReceive('completeWithTool')->once()
        ->withArgs(fn (...$args) => $args[2] === 'draft_quiz_questions')
        ->andReturn(draftResponse([candidate()]));
    // Първият проверител „познава" грешно обявения отговор → отказ веднага.
    $llm->shouldReceive('completeWithTool')->once()
        ->withArgs(fn (...$args) => $args[2] === 'answer_quiz_question')
        ->andReturn(verifierResponse(3));

    $stats = app(QuizQuestionGenerator::class)->generate(1);

    expect($stats['rejected'])->toBe(1)
        ->and(QuizQuestion::query()->count())->toBe(0);
});

it('отхвърля при ниска увереност или двусмислие', function () {
    $llm = $this->mock(LlmClient::class);
    $llm->shouldReceive('completeWithTool')
        ->withArgs(fn (...$args) => $args[2] === 'draft_quiz_questions')
        ->andReturn(draftResponse([
            candidate(),
            candidate(['question' => 'Колко завоя има пистата в Монако?']),
        ]));
    $llm->shouldReceive('completeWithTool')
        ->withArgs(fn (...$args) => $args[2] === 'answer_quiz_question')
        ->andReturn(
            verifierResponse(2, confidence: 'low'),   // №1: колебание
            verifierResponse(2, ambiguous: true),     // №2: двусмислие
        );

    $stats = app(QuizQuestionGenerator::class)->generate(2);

    expect($stats['rejected'])->toBe(2)
        ->and(QuizQuestion::query()->count())->toBe(0);
});

it('пропуска дубликат на съществуващ въпрос без да вика проверителите', function () {
    QuizQuestion::factory()->create(['question' => 'През коя година Айртон Сена печели първата си титла?']);

    $llm = $this->mock(LlmClient::class);
    $llm->shouldReceive('completeWithTool')->once()
        ->withArgs(fn (...$args) => $args[2] === 'draft_quiz_questions')
        ->andReturn(draftResponse([candidate()]));
    $llm->shouldNotReceive('completeWithTool')
        ->withArgs(fn (...$args) => $args[2] === 'answer_quiz_question');

    $stats = app(QuizQuestionGenerator::class)->generate(1);

    expect($stats['duplicates'])->toBe(1)
        ->and(QuizQuestion::query()->count())->toBe(1);
});

it('отхвърля невалидна структура без проверители', function () {
    $llm = $this->mock(LlmClient::class);
    $llm->shouldReceive('completeWithTool')->once()
        ->withArgs(fn (...$args) => $args[2] === 'draft_quiz_questions')
        ->andReturn(draftResponse([
            candidate(['options' => ['1985', '1988', '1988', '1991']]), // повторена опция
            candidate(['question' => 'Друг въпрос?', 'correct_option' => 7]),
        ]));
    $llm->shouldNotReceive('completeWithTool')
        ->withArgs(fn (...$args) => $args[2] === 'answer_quiz_question');

    $stats = app(QuizQuestionGenerator::class)->generate(2);

    expect($stats['rejected'])->toBe(2)
        ->and(QuizQuestion::query()->count())->toBe(0);
});

it('top-up мълчи при пълен басейн', function () {
    config(['quiz.pool_target' => 5]);
    QuizQuestion::factory()->count(6)->create();

    $llm = $this->mock(LlmClient::class);
    $llm->shouldNotReceive('completeWithTool');

    $this->artisan('padok:generate-quiz-questions', ['--top-up' => true])->assertSuccessful();
});

it('top-up генерира при изтънял басейн', function () {
    config(['quiz.pool_target' => 5, 'quiz.generate_batch' => 1]);
    QuizQuestion::factory()->count(2)->create();

    $llm = $this->mock(LlmClient::class);
    $llm->shouldReceive('completeWithTool')->once()
        ->withArgs(fn (...$args) => $args[2] === 'draft_quiz_questions')
        ->andReturn(draftResponse([candidate()]));
    $llm->shouldReceive('completeWithTool')->twice()
        ->withArgs(fn (...$args) => $args[2] === 'answer_quiz_question')
        ->andReturn(verifierResponse(2));

    $this->artisan('padok:generate-quiz-questions', ['--top-up' => true])->assertSuccessful();

    expect(QuizQuestion::query()->count())->toBe(3);
});
