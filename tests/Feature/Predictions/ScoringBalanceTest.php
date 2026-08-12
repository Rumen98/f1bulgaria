<?php

declare(strict_types=1);

/**
 * Пази баланса подиум/бонуси. Това не е тест на логика, а на продуктово
 * решение, което лесно се разваля с „само да вдигна pole-а на 10".
 *
 * До 12.08.2026 бонусите бяха 40 от 98 точки и усърдието биеше познаването:
 * слаб прогнозист, попълнил всичко, изпреварваше играч с идеално познат подиум.
 * След въвеждането на прогноза само с подиум това щеше да направи облекчената
 * форма безсмислена — играещият я не би могъл да достигне класирането.
 */
function bonusTotal(): int
{
    $rules = config('predictions.scoring');

    return $rules['pole'] + $rules['fastest_lap'] + $rules['dnf_exact'] + $rules['safety_car'];
}

function podiumTotal(): int
{
    return array_sum(config('predictions.scoring.exact'));
}

it('държи бонусите под една трета от максимума', function () {
    $max = podiumTotal() + bonusTotal();

    expect(bonusTotal() / $max)->toBeLessThan(0.33);
});

it('оставя точния подиум да бие пълна форма със слаб подиум', function () {
    $rules = config('predictions.scoring');

    // Познава: и тримата на точните позиции, нула бонуси.
    $sharp = podiumTotal();

    // Не познава: един точен + двама познати, но разместени, плюс всички бонуси.
    $completionist = $rules['exact']['p1'] + (2 * $rules['podium_partial']) + bonusTotal();

    expect($sharp)->toBeGreaterThan($completionist);
});

it('пази бонусите достатъчно големи, за да си струва попълването', function () {
    // Ако бонусите паднат до символични стойности, никой няма да ги пипа и
    // формата отново става само подиум.
    expect(bonusTotal())->toBeGreaterThanOrEqual(config('predictions.scoring.exact')['p3']);
});
