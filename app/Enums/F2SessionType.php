<?php

declare(strict_types=1);

namespace App\Enums;

enum F2SessionType: string
{
    case FeatureRace = 'feature_race';
    case SprintRace = 'sprint_race';

    public function label(): string
    {
        return match ($this) {
            self::FeatureRace => 'Главно състезание',
            self::SprintRace => 'Спринт',
        };
    }
}
