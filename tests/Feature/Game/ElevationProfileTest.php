<?php

declare(strict_types=1);

use App\Services\Game\ElevationProfile;

/**
 * Суровият DEM не става за каране: 30 m резолюция, тунели, дупки в покритието.
 * Тези тестове описват какво точно трябва да оцелее от почистването — релефът
 * — и какво не бива — фалшивите стръмнини.
 */
beforeEach(function () {
    $this->profile = new ElevationProfile;
});

/**
 * @param  array<int, float|null>  $values
 * @return array<int, float>
 */
function evenSegments(array $values, float $length = 30.0): array
{
    return array_fill(0, count($values), $length);
}

it('запълва дупките в покритието', function () {
    $raw = [10.0, null, null, 16.0, 18.0, 20.0, 18.0, 16.0, 14.0, 12.0];

    $result = $this->profile->clean($raw, evenSegments($raw), 0.5, 0);

    expect($result['profile'])->toHaveCount(10);
    expect($result['stats']['gaps'])->toBe(2);

    foreach ($result['profile'] as $value) {
        expect(is_finite($value))->toBeTrue();
    }
});

it('оцелява, когато датасетът няма никакво покритие', function () {
    $raw = array_fill(0, 8, null);

    $result = $this->profile->clean($raw, evenSegments($raw), 0.2, 2);

    expect($result['profile'])->toHaveCount(8);
    expect(max($result['profile']) - min($result['profile']))->toBe(0.0);
});

it('изхвърля единичен скок, какъвто дава DEM над тунел', function () {
    // Равно трасе с един връх от 40 m — точно както изглежда скалата над
    // тунела в Монако, ако ѝ повярваме.
    $raw = [12.0, 12.0, 12.0, 12.0, 52.0, 12.0, 12.0, 12.0, 12.0, 12.0];

    $result = $this->profile->clean($raw, evenSegments($raw), 0.2, 2);

    expect(max($result['profile']) - min($result['profile']))->toBeLessThan(5.0);
});

it('запазва истинския релеф', function () {
    // Плавно било: не е артефакт и трябва да оцелее.
    $raw = [];
    for ($i = 0; $i < 40; $i++) {
        $raw[] = 100 + 30 * sin($i / 40 * 2 * M_PI);
    }

    $result = $this->profile->clean($raw, evenSegments($raw), 0.2, 4);
    $range = max($result['profile']) - min($result['profile']);

    // Оригиналната амплитуда е 60 m; изглаждането свива, но не бива да я яде.
    expect($range)->toBeGreaterThan(50.0);
});

it('сваля профила до нулата', function () {
    $raw = array_map(fn (int $i): float => 700.0 + $i, range(0, 9));

    $result = $this->profile->clean($raw, evenSegments($raw), 0.5, 1);

    // Иначе трасето виси на 700 m над сцената и камерата гледа в празно.
    expect(min($result['profile']))->toBe(0.0);
});

it('налага тавана на наклона върху равномерен профил', function () {
    // Стъпало от 10 m на 4 m — 250% наклон.
    $profile = [0.0, 0.0, 0.0, 10.0, 10.0, 10.0, 10.0, 0.0];
    $spacing = 4.0;

    $limited = $this->profile->limitSlope($profile, $spacing, 0.18);

    expect($this->profile->steepestUniform($limited, $spacing))
        ->toBeLessThanOrEqual(0.1801);
});

it('не пипа профил, който вече е в рамките на тавана', function () {
    // 0.4 m на 4 m = 10%, под тавана от 18%.
    $profile = [0.0, 0.4, 0.8, 1.2, 0.8, 0.4];

    $limited = $this->profile->limitSlope($profile, 4.0, 0.18);

    foreach ($profile as $i => $value) {
        expect($limited[$i])->toBeGreaterThan($value - 0.01);
        expect($limited[$i])->toBeLessThan($value + 0.01);
    }
});

it('затваря цикъла — краят не може да скочи спрямо началото', function () {
    // Ако ограничаването гледаше профила като отсечка вместо като цикъл,
    // колата щеше да пропада при всяко пресичане на стартовата линия.
    $profile = [0.0, 2.0, 4.0, 6.0, 8.0, 10.0, 12.0, 14.0];
    $spacing = 4.0;

    $limited = $this->profile->limitSlope($profile, $spacing, 0.18);

    $closingSlope = abs($limited[0] - $limited[count($limited) - 1]) / $spacing;

    expect($closingSlope)->toBeLessThanOrEqual(0.1801);
});
