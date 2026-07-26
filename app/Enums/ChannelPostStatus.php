<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Състояние на публикацията в изходящата опашка.
 */
enum ChannelPostStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Чакаща',
            self::Sent => 'Изпратена',
            self::Failed => 'Провалена',
            self::Skipped => 'Пропусната',
        };
    }
}
