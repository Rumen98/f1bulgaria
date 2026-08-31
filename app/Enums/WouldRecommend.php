<?php

declare(strict_types=1);

namespace App\Enums;

enum WouldRecommend: string
{
    case Yes = 'yes';
    case No = 'no';
    case Maybe = 'maybe';

    public function label(): string
    {
        return match ($this) {
            self::Yes => 'Да',
            self::No => 'Не',
            self::Maybe => 'Може би',
        };
    }
}
