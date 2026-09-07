<?php

declare(strict_types=1);

/**
 * Пази „Двубой в кръга“ от нещата, които се чупят тихо: сървърният рендер,
 * посоката на корекцията за гориво, оста на деградацията, изхвърлянето на
 * мръсните обиколки и името на фийчъра.
 *
 * От Pest не тръгва браузър, затова се проверява източникът — същият похват
 * като при отзивчивостта на графиките. Самите наклони бяха измерени веднъж
 * срещу нарочно построени времена: стинт с известни 0,10 и 0,20 с/обиколка
 * излиза точно толкова след корекцията. Тук се пази формулата, защото
 * повторната сметка иска Node, а той не е част от тестовия набор.
 */
function driverDuelSource(): string
{
    return file_get_contents(resource_path('js/Components/RaceData/DriverDuel.vue'));
}

it('приема точно уговорените входни данни', function (string $prop) {
    expect(driverDuelSource())->toContain("{$prop}: {");
})->with(['laps', 'stints', 'pace', 'perDriver', 'positions', 'fuelCorrection', 'totalLaps', 'neutralisations', 'preselect']);

it('не пипа браузърни глобали', function (string $global) {
    // Компонентът няма onMounted: всичко е чисти сметки, за да мине и
    // сървърният рендер. Първото window тук сваля цялата страница в Node.
    expect(driverDuelSource())->not->toContain($global);
})->with(['window.', 'document.', 'Math.random']);

it('връща на времето това, което горивото е спестило', function () {
    // Знакът е целият смисъл: болидът олеква с всяка обиколка и ако корекцията
    // се извади вместо да се върне, наклонът на стинта става отрицателен —
    // излиза, че гумата се подобрява с износването.
    expect(driverDuelSource())->toContain('seconds + (lap - 1) * props.fuelCorrection');
});

it('изхвърля обиколките с пит и тези под неутрализация', function () {
    // Обиколка на влизане в пита е с двадесет секунди по-бавна: остане ли
    // вътре, тя сама определя мащаба и на двете графики.
    expect(driverDuelSource())
        ->toContain('!dirty.has(lap)')
        ->toContain('!neutralisedLaps.value.has(lap)');
});

it('маха и изходната обиколка след спирането', function () {
    // Сървърът я познава по `is_pit_out_lap`, но до клиента идват само
    // номерата на спиранията — оттам и `+1`. Без нея всеки стинт започва с
    // фалшив връх от пит лейна и наклонът излиза отрицателен.
    expect(driverDuelSource())->toContain('laps.add(lap + 1)');
});

it('мери деградацията по възрастта на гумата, не по номера на обиколката', function () {
    // Номерът на обиколката смесва два ефекта — износване и олекване. Оста е
    // възрастта, а горивото се маха отделно; иначе вторият стинт винаги
    // изглежда по-издръжлив от първия само защото идва по-късно.
    expect(driverDuelSource())->toContain('(segment.age ?? 0) + (lap - segment.from)');
});

it('не показва минус нула при равно темпо', function () {
    // Наклон от −0,004 без закръгляне преди знака се изписва „−0,00 с/об.“ и
    // прилича на счупена сметка.
    expect(driverDuelSource())->toContain('Number(stint.loss.toFixed(2))');
});

it('стъпва на вече съществуващите графики', function () {
    // Своя SVG тук би бил четвърта геометрия за поддръжка и първата, която
    // ще забравим да оправим за телефон.
    expect(driverDuelSource())
        ->toContain("import LapLineChart from '@/Components/RaceData/LapLineChart.vue'")
        ->toContain("import StintBars from '@/Components/RaceData/StintBars.vue'")
        ->not->toContain('plot(');
});

it('не се нарича сравнение', function () {
    // /compare и /rivalries вече заемат тази дума в сайта и говорят за кариери,
    // а тук става дума за едно състезание.
    expect(driverDuelSource())->not->toMatch('/сравн/iu');
});
