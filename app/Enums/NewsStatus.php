<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Статус на новинарска статия в модерационния pipeline.
 */
enum NewsStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case AutoPublished = 'auto_published';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Чакаща',
            self::Approved => 'Одобрена',
            self::Rejected => 'Отхвърлена',
            self::AutoPublished => 'Авто-публикувана',
        };
    }

    /**
     * Статуси, видими публично на сайта.
     *
     * @return array<int, self>
     */
    public static function publiclyVisible(): array
    {
        return [self::Approved, self::AutoPublished];
    }
}
