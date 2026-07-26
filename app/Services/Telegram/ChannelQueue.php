<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Enums\ChannelPostKind;
use App\Models\ChannelPost;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Поставя публикация в опашката, най-много веднъж за (тема, вид).
 *
 * Идемпотентността е в уникалния индекс на таблицата, не в проверката преди
 * вмъкването: синхроните могат да се застъпят и две едновременни проверки
 * биха минали и двете. Затова хващаме нарушението на индекса.
 */
class ChannelQueue
{
    /**
     * @return bool true, ако редът е нов
     */
    public function enqueue(
        Model $subject,
        ChannelPostKind $kind,
        string $body,
        ?Carbon $availableAt = null,
    ): bool {
        $keys = [
            'channel' => 'telegram',
            'kind' => $kind->value,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ];

        if (ChannelPost::query()->where($keys)->exists()) {
            return false;
        }

        try {
            ChannelPost::query()->create([
                ...$keys,
                'body' => $body,
                'available_at' => $availableAt,
            ]);
        } catch (QueryException $e) {
            // 23000 = нарушен уникален индекс, тоест друг процес е изпреварил.
            // Всичко останало е истинска грешка и трябва да излезе нагоре.
            if ($e->getCode() === '23000') {
                return false;
            }

            throw $e;
        }

        return true;
    }
}
