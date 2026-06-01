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
