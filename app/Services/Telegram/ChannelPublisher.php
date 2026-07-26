<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Enums\ChannelPostStatus;
use App\Models\ChannelPost;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Изпразва опашката `channel_posts` към Telegram.
 *
 * Грешка в един пост не спира останалите — редът се маркира и обработката
 * продължава, както прави и NewsAggregationService при провален източник.
 * Идемпотентен: изпратените редове не се избират повторно.
 */
class ChannelPublisher
{
    public function __construct(
        private readonly TelegramClient $client,
    ) {}

    /**
     * @return array{processed:int, sent:int, failed:int, errors:array<int, string>}
     */
    public function publish(?int $limit = null, bool $dryRun = false): array
    {
        $stats = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'errors' => []];

        if (! $dryRun && ! (bool) config('channel.enabled', false)) {
            $stats['errors'][] = 'Каналът е изключен (CHANNEL_ENABLED=false) — нищо не е изпратено.';

            return $stats;
        }

        if (! $dryRun && ! $this->client->hasCredentials()) {
            $stats['errors'][] = 'Липсва TELEGRAM_BOT_TOKEN или TELEGRAM_CHAT_ID — нищо не е изпратено.';

            return $stats;
        }

        $posts = ChannelPost::query()
            ->ready()
            ->limit($limit ?? (int) config('channel.batch_limit', 10))
            ->get();

        $sleepMs = (int) config('channel.post_sleep_ms', 1200);

        foreach ($posts as $index => $post) {
            $stats['processed']++;

            if ($dryRun) {
                continue;
            }

            // Паузата е МЕЖДУ постовете, не след последния — иначе всяко
            // пускане на командата виси излишно.
            if ($index > 0 && $sleepMs > 0) {
                usleep($sleepMs * 1000);
            }

            try {
                $this->send($post);
                $stats['sent']++;
            } catch (TelegramPermanentException $e) {
                $stats['failed']++;
                $stats['errors'][] = "пост #{$post->id} ({$post->kind->value}): {$e->getMessage()}";
                $this->markFailed($post, $e->getMessage());
            } catch (Throwable $e) {
                $stats['failed']++;
                $stats['errors'][] = "пост #{$post->id} ({$post->kind->value}): {$e->getMessage()}";
                $this->markRetryable($post, $e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * @throws TelegramException
     */
    private function send(ChannelPost $post): void
    {
        // Броячът се вдига ПРЕДИ заявката. Ако процесът умре по средата
        // (OOM, deploy, reboot), редът пак приближава тавана и не се върти
        // безкрайно — иначе един пост, който убива worker-а, блокира опашката.
        $post->increment('attempts');

        $chunks = TelegramText::chunk($post->body);
        $sleepMs = (int) config('channel.post_sleep_ms', 1200);

        // Вече изпратен ред, върнат в pending, значи промяна в съдържанието —
        // редактираме на място, за да няма второ известие за едно събитие.
        //
        // Многочастов текст не се редактира: пазим само id-то на първото
        // съобщение, а редакция само на него би отрязала останалото. В този
        // случай минаваме на ново изпращане.
        if ($post->telegram_message_id !== null && count($chunks) === 1) {
            try {
                $this->client->edit((int) $post->telegram_message_id, $chunks[0]);

                $post->update([
                    'status' => ChannelPostStatus::Sent->value,
                    'last_error' => null,
                ]);

                return;
            } catch (TelegramPermanentException $e) {
                // Оригиналът го няма — например изтрит ръчно от канала
                // (Telegram връща MESSAGE_ID_INVALID). Пращаме наново вместо
                // да се предадем: инак постът остава грешен завинаги.
                Log::warning("Telegram: редакцията на пост [{$post->id}] се провали ({$e->getMessage()}) — пращам наново.");
            }
        }

        $firstMessageId = null;

        foreach ($chunks as $index => $chunk) {
            if ($index > 0 && $sleepMs > 0) {
                usleep($sleepMs * 1000);
            }

            $messageId = $this->client->send($chunk, silent: $post->kind->isSilent());

            $firstMessageId ??= $messageId;
        }

        $post->update([
            'status' => ChannelPostStatus::Sent->value,
            'telegram_message_id' => $firstMessageId,
            'sent_at' => now(),
            'last_error' => null,
        ]);
    }

    /**
     * Постоянна грешка — не се пробва повече.
     *
     * Каналът НЕ се изключва автоматично: Telegram връща 403 и когато ботът
     * е още в канала, но без право да публикува, а от отговора на sendMessage
     * двата случая не се различават.
     */
    private function markFailed(ChannelPost $post, string $error): void
    {
        $post->update([
            'status' => ChannelPostStatus::Failed->value,
            'last_error' => $error,
        ]);

        Log::error("Telegram: постоянна грешка при пост [{$post->id}]: {$error}");
    }

    /**
     * Временна грешка — остава pending, освен ако не е изчерпал опитите.
     */
    private function markRetryable(ChannelPost $post, string $error): void
    {
        $maxAttempts = (int) config('channel.max_attempts', 5);
        $exhausted = $post->attempts >= $maxAttempts;

        $post->update([
            'status' => $exhausted
                ? ChannelPostStatus::Failed->value
                : ChannelPostStatus::Pending->value,
            'last_error' => $error,
        ]);

        if ($exhausted) {
            Log::error("Telegram: пост [{$post->id}] се провали след {$post->attempts} опита: {$error}");

            return;
        }

        Log::warning("Telegram: временна грешка при пост [{$post->id}] (опит {$post->attempts}/{$maxAttempts}): {$error}");
    }
}
