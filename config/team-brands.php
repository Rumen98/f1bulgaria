<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Team brand presentation config
|--------------------------------------------------------------------------
|
| Презентационни настройки за премиум wordmark + геометричен badge на всеки
| отбор (използва се от resources/js/Components/Team/TeamBrand.vue, споделя се
| към фронтенда през HandleInertiaRequests). Legal-safe: само текст + абстрактна
| геометрия, без копирани лога.
|
| shape: 'shield' | 'hexagon' | 'angular' | 'classic' | 'circle'
| colors: [primary, secondary] — primary е за текста/акцента, secondary за фона
| font: 'sans' | 'sans-bold' | 'serif' | 'italic'
| letterSpacing: CSS em стойност (по избор)
|
| За легендарни отбори без запис тук TeamBrand.vue пада към простата монограма
| с color_hex на конструктора.
|
*/

return [
    'ferrari' => ['shape' => 'shield', 'colors' => ['#DC0000', '#000000'], 'font' => 'italic', 'letterSpacing' => '0.05em'],
    'mercedes' => ['shape' => 'hexagon', 'colors' => ['#00D2BE', '#000000'], 'font' => 'sans', 'letterSpacing' => '0.1em'],
    'red_bull' => ['shape' => 'angular', 'colors' => ['#1E41FF', '#FFC906'], 'font' => 'sans-bold'],
    'mclaren' => ['shape' => 'angular', 'colors' => ['#FF8000', '#000000'], 'font' => 'sans-bold'],
    'williams' => ['shape' => 'classic', 'colors' => ['#00A0DE', '#FFFFFF'], 'font' => 'sans'],
    'aston_martin' => ['shape' => 'hexagon', 'colors' => ['#006F62', '#FFFFFF'], 'font' => 'serif'],
    'alpine' => ['shape' => 'angular', 'colors' => ['#FF87BC', '#0090D2'], 'font' => 'sans'],
    'haas' => ['shape' => 'angular', 'colors' => ['#FFFFFF', '#FF6F71'], 'font' => 'sans-bold'],
    'rb' => ['shape' => 'angular', 'colors' => ['#6692FF', '#000000'], 'font' => 'sans-bold'],
    'kick_sauber' => ['shape' => 'classic', 'colors' => ['#52E252', '#000000'], 'font' => 'sans'],
];
