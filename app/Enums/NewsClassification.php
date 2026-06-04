<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Тематична класификация на новинарска статия (попълва се от LLM в по-късна стъпка).
 */
enum NewsClassification: string
{
    case Race = 'race';
    case Driver = 'driver';
    case Technical = 'technical';
    case Rumor = 'rumor';
    case Business = 'business';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Race => 'Състезание',
            self::Driver => 'Пилот',
            self::Technical => 'Техническо',
            self::Rumor => 'Слух',
            self::Business => 'Бизнес',
            self::Other => 'Друго',
        };
    }

    /**
     * Акцентен цвят за категорията (за generic банер на новина).
     */
    public function color(): string
    {
        return match ($this) {
            self::Race => '#e10600',
            self::Driver => '#3b82f6',
            self::Technical => '#a855f7',
            self::Rumor => '#f59e0b',
            self::Business => '#10b981',
            self::Other => '#71717a',
        };
    }
}
