<?php

declare(strict_types=1);

use App\Support\BulgarianSort;

/**
 * Изпълнява callback-а с изключен ICU collator, за да покрием и fallback-а —
 * продукционният сървър може да е без php-intl.
 *
 * @param  callable(): mixed  $callback
 */
function withoutIntlCollator(callable $callback): mixed
{
    $collator = new ReflectionProperty(BulgarianSort::class, 'collator');
    $resolved = new ReflectionProperty(BulgarianSort::class, 'collatorResolved');

    $previousCollator = $collator->getValue();
    $previousResolved = $resolved->getValue();

    $collator->setValue(null, null);
    $resolved->setValue(null, true);

    try {
        return $callback();
    } finally {
        $collator->setValue(null, $previousCollator);
        $resolved->setValue(null, $previousResolved);
    }
}

/**
 * @param  array<int, string>  $names
 * @return array<int, string>
 */
function sortBulgarian(array $names): array
{
    return collect($names)
        ->sortBy(fn (string $name) => BulgarianSort::key($name))
        ->values()
        ->all();
}

it('подрежда кирилицата по българската азбука', function () {
    expect(sortBulgarian(['Ферстапен', 'Албон', 'Хамилтън', 'Юки Цунода', 'Шарл Льоклер']))
        ->toBe(['Албон', 'Ферстапен', 'Хамилтън', 'Шарл Льоклер', 'Юки Цунода']);
});

it('слага кирилицата преди латиницата', function () {
    expect(sortBulgarian(['Zhou Guanyu', 'Ферстапен', 'Alexander Albon', 'Албон']))
        ->toBe(['Албон', 'Ферстапен', 'Alexander Albon', 'Zhou Guanyu']);
});

it('пази същия ред и без intl', function () {
    $names = ['Zhou Guanyu', 'Ферстапен', 'Alexander Albon', 'Албон', 'Хамилтън'];

    $withIntl = sortBulgarian($names);
    $withoutIntl = withoutIntlCollator(fn () => sortBulgarian($names));

    expect($withoutIntl)->toBe($withIntl)
        ->and($withoutIntl)->toBe(['Албон', 'Ферстапен', 'Хамилтън', 'Alexander Albon', 'Zhou Guanyu']);
});

it('приравнява латинските диакритики към базовата буква и без intl', function () {
    $names = ['Zhou', 'Räikkönen', 'Alonso', 'Hülkenberg'];

    expect(withoutIntlCollator(fn () => sortBulgarian($names)))
        ->toBe(['Alonso', 'Hülkenberg', 'Räikkönen', 'Zhou']);
});

it('връща празен ключ за празно име', function () {
    expect(BulgarianSort::key(''))->toBe('')
        ->and(BulgarianSort::key('   '))->toBe('');
});

it('ползва ICU, когато разширението intl е налично', function () {
    expect(BulgarianSort::usesIntl())->toBe(extension_loaded('intl'));
});
