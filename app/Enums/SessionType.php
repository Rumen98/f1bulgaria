<?php

declare(strict_types=1);

namespace App\Enums;

enum SessionType: string
{
    case FP1 = 'fp1';
    case FP2 = 'fp2';
    case FP3 = 'fp3';
    case Qualifying = 'qualifying';
    case SprintQuali = 'sprint_quali';
    case Sprint = 'sprint';
    case Race = 'race';

    /**
     * Ред на показване в рамките на уикенда.
     *
     * Едната подредба покрива и двата формата: при обикновен уикенд дава
     * FP1→FP2→FP3→Квалификация→Състезание, а при спринтов —
     * FP1→Спринт квалификация→Спринт→Квалификация→Състезание, защото
     * несъществуващите сесии просто липсват.
     */
    public function order(): int
    {
        return match ($this) {
            self::FP1 => 1,
            self::FP2 => 2,
            self::FP3 => 3,
            self::SprintQuali => 4,
            self::Sprint => 5,
            self::Qualifying => 6,
            self::Race => 7,
        };
    }

    /**
     * Човешко име на български за показване в UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::FP1 => 'Свободна тренировка 1',
            self::FP2 => 'Свободна тренировка 2',
            self::FP3 => 'Свободна тренировка 3',
            self::Qualifying => 'Квалификация',
            self::SprintQuali => 'Спринт квалификация',
            self::Sprint => 'Спринт',
            self::Race => 'Състезание',
        };
    }
}
