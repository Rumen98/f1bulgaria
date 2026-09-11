<?php

declare(strict_types=1);

/**
 * Пази четимостта на графиките в „Данни“ по телефон.
 *
 * Правилата тук се губят тихо: връща се едно `font-size="11"` и на екран от
 * 390 пиксела етикетът пак се рендира като 4,5 — виждаемо само с телефон в
 * ръка. От Pest не тръгва браузър, затова се проверява източникът.
 */
$svgCharts = ['LapLineChart', 'TrackTemperature', 'TyreDegradation', 'TelemetryTrace', 'TrackSpeedMap'];

$allCharts = [...$svgCharts, 'PaceBars', 'PositionSwing', 'ChampionshipSwing'];

function raceDataComponent(string $name): string
{
    return file_get_contents(resource_path("js/Components/RaceData/{$name}.vue"));
}

it('взима геометрията си от useChartBox', function (string $name) {
    expect(raceDataComponent($name))
        ->toContain("from '@/composables/useChartBox'")
        ->toContain('useChartBox(');
})->with($allCharts);

it('няма зашит размер на шрифта', function (string $name) {
    expect(raceDataComponent($name))
        ->not->toMatch('/font-size="\d/')
        ->toContain(':font-size="');
})->with($svgCharts);

it('не строи собствено поле с plot()', function (string $name) {
    // Директният plot(800, 300) е точно бъгът: фиксиран viewBox независимо от
    // ширината на контейнера.
    expect(raceDataComponent($name))->not->toContain('plot(');
})->with($allCharts);

it('започва от широката геометрия заради сървърния рендер', function () {
    $composable = file_get_contents(resource_path('js/composables/useChartBox.js'));

    // Различна начална стойност би дала несъвпадение при хидратацията.
    expect($composable)->toContain('const isNarrow = ref(false);');
});

it('пипа браузърните глобали само след монтиране', function () {
    $composable = file_get_contents(resource_path('js/composables/useChartBox.js'));

    $mounted = strpos($composable, 'onMounted(');

    foreach (['window.', 'ResizeObserver'] as $global) {
        expect(strpos($composable, $global))->toBeGreaterThan($mounted, "{$global} е достъпен преди onMounted");
    }
});
