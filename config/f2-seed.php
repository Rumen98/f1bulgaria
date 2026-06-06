<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Начални данни за Формула 2 (F2SeedSeeder)
|--------------------------------------------------------------------------
|
| Минимален placeholder скелет — шампионите по сезони + отборите им. Данните
| се поддържат ръчно през Filament; това е само отправна точка. Всеки запис:
| [first, last, slug, country_code, team_name, team_slug].
|
*/

return [
    // [year, is_current, champion[6]]
    'champions' => [
        [2017, false, ['Charles', 'Leclerc', 'charles-leclerc', 'MCO', 'Prema Racing', 'prema']],
        [2018, false, ['George', 'Russell', 'george-russell', 'GBR', 'ART Grand Prix', 'art']],
        [2019, false, ['Nyck', 'de Vries', 'nyck-de-vries', 'NLD', 'ART Grand Prix', 'art']],
        [2020, false, ['Mick', 'Schumacher', 'mick-schumacher', 'DEU', 'Prema Racing', 'prema']],
        [2021, false, ['Oscar', 'Piastri', 'oscar-piastri', 'AUS', 'Prema Racing', 'prema']],
        [2022, false, ['Felipe', 'Drugovich', 'felipe-drugovich', 'BRA', 'MP Motorsport', 'mp-motorsport']],
        [2023, false, ['Théo', 'Pourchaire', 'theo-pourchaire', 'FRA', 'ART Grand Prix', 'art']],
        [2024, false, ['Gabriel', 'Bortoleto', 'gabriel-bortoleto', 'BRA', 'Invicta Racing', 'invicta']],
    ],

    // Текущ сезон (placeholder) — българската връзка: Цолов. Без обявен шампион.
    'current' => [
        'year' => 2025,
        'drivers' => [
            ['Nikola', 'Tsolov', 'nikola-tsolov', 'BGR', 'Campos Racing', 'campos'],
        ],
    ],
];
