<?php

declare(strict_types=1);

/**
 * Времевата лента, картите на моментите и курсорът в двете синхронизирани
 * графики.
 *
 * От Pest не тръгва браузър и в проекта няма изпълнител на Vue тестове,
 * затова се проверява източникът. Правилата тук се губят тихо: курсор без
 * `default: null` чупи всяко текущо извикване на графиката, а плъзгач без
 * едро поле за пръст просто не се улучва с палец — и двете се виждат чак с
 * телефон в ръка.
 */
function timelineComponent(string $name): string
{
    return file_get_contents(resource_path("js/Components/RaceData/{$name}.vue"));
}

it('дава на плъзгача едро поле за пръст и достъпно име', function () {
    // 44 пиксела (h-11) е долната граница за цел под палец.
    expect(timelineComponent('StoryTimeline'))
        ->toContain('type="range"')
        ->toContain('aria-label="Обиколка"')
        ->toContain('h-11');
});

it('праща избраната обиколка нагоре', function () {
    expect(timelineComponent('StoryTimeline'))
        ->toContain("defineEmits(['update:lap'])")
        ->toContain("emit('update:lap'");
});

it('именува всеки маркер от лентата', function () {
    expect(timelineComponent('StoryTimeline'))->toContain(':aria-label="markerLabel(');
});

it('изброява поименно кои графики следват курсора', function (string $chart) {
    // „Цялата страница се движи“ би било невярно: телеметрията и картата носят
    // данни за една обиколка и не могат да се преместят.
    expect(timelineComponent('StoryTimeline'))->toContain($chart);
})->with(['стратегията по гуми', 'позициите', 'изоставането от лидера']);

it('нарича размените „смени на позиции“', function () {
    $source = timelineComponent('StoryTimeline');

    // Гледа се само видимата част: коментарът в скрипта има право да назове
    // отхвърлената дума, надписът на екрана — не. Наборът на OpenF1 включва
    // размени от питстопове и наказания след финала.
    $template = substr($source, (int) strpos($source, '<template>'));

    expect($template)
        ->toContain('Смени на позиции')
        ->not->toContain('зпреварван');
});

it('прави курсора опционален и в двете синхронизирани графики', function (string $name) {
    expect(timelineComponent($name))
        ->toContain('cursor: { type: Number, default: null }')
        ->toContain('Number.isFinite(props.cursor)');
})->with(['LapLineChart', 'StintBars']);

it('рисува курсора по вече съществуващата скала', function () {
    // Втора сметка значи втори отговор на въпроса къде стои обиколка 20.
    expect(timelineComponent('StintBars'))->toContain('percent(props.cursor, props.cursor)');
    expect(timelineComponent('LapLineChart'))->toContain('x.value(props.cursor)');
});

it('озаглавява моментите „Къде се решаваше“', function () {
    expect(timelineComponent('RaceMoments'))->toContain('Къде се решаваше');
});

it('връзва картите с времевата лента', function () {
    expect(timelineComponent('RaceMoments'))
        ->toContain("defineEmits(['select'])")
        ->toContain("emit('select', card.from)");
});

it('не говори за причина в картите на моментите', function (string $word) {
    // Текстът идва от шаблони в PHP; всяка дума за причина тук би била наша
    // измислица върху числа, които не я носят.
    expect(timelineComponent('RaceMoments'))->not->toContain($word);
})->with(['спечели', 'реши', 'обърна', 'заради']);
