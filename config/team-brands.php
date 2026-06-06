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
| Ключът е slug-ът на конструктора (както е в базата), за да съвпада с реалните
| записи. name_bg е българското изписване (журналистическа норма).
|
| shape: 'shield' | 'hexagon' | 'angular' | 'classic' | 'circle'
| colors: [primary, secondary] — primary е за текста/акцента, secondary за фона
| font: 'sans' | 'sans-bold' | 'serif' | 'italic'
| letterSpacing: CSS em стойност (по избор)
|
| За отбори без запис тук TeamBrand.vue пада към простата монограма, а за името
| се ползва оригиналното (латинско) име от базата.
|
*/

return [
    'ferrari' => ['name_bg' => 'Ферари', 'shape' => 'shield', 'colors' => ['#DC0000', '#000000'], 'font' => 'italic', 'letterSpacing' => '0.05em'],
    'mercedes' => ['name_bg' => 'Мерцедес', 'shape' => 'hexagon', 'colors' => ['#00D2BE', '#000000'], 'font' => 'sans', 'letterSpacing' => '0.1em'],
    'red-bull' => ['name_bg' => 'Ред Бул', 'shape' => 'angular', 'colors' => ['#1E41FF', '#FFC906'], 'font' => 'sans-bold'],
    'mclaren' => ['name_bg' => 'Макларън', 'shape' => 'angular', 'colors' => ['#FF8000', '#000000'], 'font' => 'sans-bold'],
    'williams' => ['name_bg' => 'Уилямс', 'shape' => 'classic', 'colors' => ['#00A0DE', '#FFFFFF'], 'font' => 'sans'],
    'aston-martin' => ['name_bg' => 'Астън Мартин', 'shape' => 'hexagon', 'colors' => ['#006F62', '#FFFFFF'], 'font' => 'serif'],
    'alpine-f1-team' => ['name_bg' => 'Алпин', 'shape' => 'angular', 'colors' => ['#FF87BC', '#0090D2'], 'font' => 'sans'],
    'haas-f1-team' => ['name_bg' => 'Хаас', 'shape' => 'angular', 'colors' => ['#FFFFFF', '#FF6F71'], 'font' => 'sans-bold'],
    'rb-f1-team' => ['name_bg' => 'РБ', 'shape' => 'angular', 'colors' => ['#6692FF', '#000000'], 'font' => 'sans-bold'],
    'audi' => ['name_bg' => 'Ауди', 'shape' => 'classic', 'colors' => ['#BB0A30', '#000000'], 'font' => 'sans-bold'],
    'cadillac-f1-team' => ['name_bg' => 'Кадилак', 'shape' => 'classic', 'colors' => ['#A6953F', '#000000'], 'font' => 'serif'],

    // Историческо изписване (Заубер) — за легендарните сезони.
    'kick-sauber' => ['name_bg' => 'Кик Заубер', 'shape' => 'classic', 'colors' => ['#52E252', '#000000'], 'font' => 'sans'],
    'sauber' => ['name_bg' => 'Заубер', 'shape' => 'classic', 'colors' => ['#52E252', '#000000'], 'font' => 'sans'],
    'alphatauri' => ['name_bg' => 'Алфа Таури', 'shape' => 'angular', 'colors' => ['#2B4562', '#FFFFFF'], 'font' => 'sans'],
    'alfa-romeo' => ['name_bg' => 'Алфа Ромео', 'shape' => 'classic', 'colors' => ['#900000', '#FFFFFF'], 'font' => 'serif'],
    'renault' => ['name_bg' => 'Рено', 'shape' => 'classic', 'colors' => ['#FFF500', '#000000'], 'font' => 'sans-bold'],
    'toro-rosso' => ['name_bg' => 'Торо Росо', 'shape' => 'angular', 'colors' => ['#0000FF', '#FFFFFF'], 'font' => 'sans'],
    'racing-point' => ['name_bg' => 'Рейсинг Пойнт', 'shape' => 'hexagon', 'colors' => ['#F596C8', '#FFFFFF'], 'font' => 'sans'],
    'force-india' => ['name_bg' => 'Форс Индия', 'shape' => 'hexagon', 'colors' => ['#F596C8', '#FFFFFF'], 'font' => 'sans'],
    'lotus-f1' => ['name_bg' => 'Лотус', 'shape' => 'classic', 'colors' => ['#FFB800', '#000000'], 'font' => 'serif'],
];
