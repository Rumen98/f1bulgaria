<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Състояние на hero секцията на началната страница.
 */
enum HeroState: string
{
    case Active = 'active';       // в момента тече състезателен уикенд
    case Upcoming = 'upcoming';   // предстоящо състезание (извън уикенд)
    case OffSeason = 'off_season'; // няма бъдещи състезания
}
