<?php

declare(strict_types=1);

/**
 * Промптите за новини носят таблица с транслитерации, а каноничните имена
 * живеят в config/driver-names-bg.php и config/team-brands.php. Две копия
 * на един и същ списък се разминават — и се разминаха: промптът казваше
 * „Леклер", докато страницата на пилота казва „Шарл Льоклер", тоест един
 * пилот с два правописа на един сайт.
 *
 * Тези тестове не позволяват разминаването да се повтори тихо.
 */
function newsPrompts(): string
{
    return config('news.classifier_system_prompt')."\n".config('news.full_article_system_prompt');
}

it('промптът не противоречи на каноничните имена на пилотите', function (string $slug) {
    $canonical = config("driver-names-bg.{$slug}");
    expect($canonical)->not->toBeNull("липсва каноничното име за {$slug}");

    // Фамилията е последната дума от каноничното име.
    $surname = last(explode(' ', $canonical));

    $prompts = newsPrompts();

    // Ако промптът изобщо споменава пилота, трябва да е с каноничната форма.
    $mentions = mb_stripos($prompts, $surname) !== false;

    expect($mentions)->toBeTrue(
        "Промптът не споменава „{$surname}“ ({$slug}) — или го изпусни от таблицата, или го изпиши канонично."
    );
})->with([
    'max-verstappen',
    'lewis-hamilton',
    'charles-leclerc',
    'lando-norris',
    'george-russell',
]);

it('грешните форми се срещат само като изричен контрапример', function (string $wrong) {
    // „Леклер" излезе в продъкшън, защото беше вкарано в промпта като
    // предписание, докато config/driver-names-bg.php казва „Шарл Льоклер".
    //
    // Промптът СЪДЪРЖА грешни форми нарочно („…НЕ „Гран При""), затова
    // проверката не е за отсъствие, а за контекст: всяко срещане трябва да
    // е след „НЕ" в рамките на 30 символа. Предписание без такова
    // отрицание значи, че сме инструктирали модела да пише грешно.
    $prompts = newsPrompts();
    expect($prompts)->not->toBeEmpty();

    $offset = 0;

    while (($pos = mb_strpos($prompts, $wrong, $offset)) !== false) {
        $before = mb_substr($prompts, max(0, $pos - 14), min(14, $pos));

        // Отрицанието трябва да стои НЕПОСРЕДСТВЕНО преди формата и да е
        // отделна дума. Първата версия търсеше подниза „не" някъде в
        // предходните 30 символа и мълчаливо приемаше „…по звучене. Фамилията
        // "Leclerc" е "Леклер"" за отрицание, защото „звучене" свършва на
        // „не". Така тестът пропусна точно грешката, за която беше писан.
        $negated = preg_match('/(?<!\p{L})(НЕ|не)\s*["„]?\s*$/u', $before) === 1;

        expect($negated)->toBeTrue("„{$wrong}“ се среща в промпта без отрицание пред него: „…{$before}“");

        $offset = $pos + mb_strlen($wrong);
    }
})->with([
    'Леклерк',
    'Леклер"',
    'Ферстапен',
    'Макларен ',
    'Алпайн',
    'Зандвоорт',
    'Гран При',
]);

it('промптът не противоречи на каноничните имена на отборите', function (string $key) {
    $canonical = config("team-brands.{$key}.name_bg");
    expect($canonical)->not->toBeNull("липсва каноничното име за {$key}");

    expect(newsPrompts())->toContain($canonical);
})->with([
    'ferrari',
    'mercedes',
    'red-bull',
    'mclaren',
    'aston-martin',
]);
