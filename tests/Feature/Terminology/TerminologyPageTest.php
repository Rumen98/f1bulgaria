<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

it('страницата с терминология връща 200 с термини и категории', function () {
    $this->get('/terminologiya')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Terminologiya/Index')
            ->has('categories')
            ->has('terms'));
});

it('речникът съдържа 60+ термина в 6 категории', function () {
    $terms = config('f1-glossary.terms');
    $categories = config('f1-glossary.categories');

    expect(count($terms))->toBeGreaterThanOrEqual(60)
        ->and($categories)->toHaveKeys(['car', 'race', 'tires', 'track', 'strategy', 'driver']);

    // Всеки термин има задължителните полета и валидна категория.
    foreach ($terms as $t) {
        expect($t)->toHaveKeys(['term_bg', 'term_en', 'definition_bg', 'category'])
            ->and($t['category'])->toBeIn(array_keys($categories));
    }
});

it('съдържа ключови термини (DRS, Ъндъркът, Грейнинг)', function () {
    $bgTerms = collect(config('f1-glossary.terms'))->pluck('term_bg');
    $enTerms = collect(config('f1-glossary.terms'))->pluck('term_en');

    expect($enTerms)->toContain('DRS')
        ->and($bgTerms)->toContain('Ъндъркът')
        ->and($bgTerms)->toContain('Грейнинг');
});
