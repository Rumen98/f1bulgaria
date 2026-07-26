<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Enums\ChannelPostKind;
use App\Enums\ChannelPostStatus;
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

        $existing = ChannelPost::query()->where($keys)->first();

        if ($existing !== null) {
            return $this->refresh($existing, $body);
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

    /**
     * Съдържанието на вече поставена публикация се е променило — например
     * временната класация е станала окончателна.
     *
     * Вместо втори пост връщаме реда в pending, като ЗАПАЗВАМЕ
     * telegram_message_id. По него издателят разбира, че става дума за
     * редакция на място, а не за ново съобщение.
     *
     * @return bool false — тук никога не се създава нов ред
     */
    private function refresh(ChannelPost $post, string $body): bool
    {
        if ($post->body === $body) {
            return false;
        }

        // Провалилите се публикации не се съживяват от промяна в текста —
        // причината (изгонен бот, грешен chat_id) е другаде и ще се повтори.
        if ($post->status === ChannelPostStatus::Failed) {
            return false;
        }

        $post->update([
            'body' => $body,
            'status' => ChannelPostStatus::Pending->value,
        ]);

        return false;
    }
}
