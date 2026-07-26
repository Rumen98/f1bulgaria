<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use Illuminate\Console\Command;

/**
 * Еднократна настройка: превръща @username на канала в числово chat_id.
 */
class ResolveChannelChatIdCommand extends Command
{
    protected $signature = 'channel:resolve-chat-id {username : Публичното име на канала, напр. @padokbg}';

    protected $description = 'Вади числовото chat_id на канала по @username — стойността за TELEGRAM_CHAT_ID.';

    public function handle(TelegramClient $client): int
    {
        /** @var string $username */
        $username = $this->argument('username');

        try {
            $chatId = $client->resolveChatId($username);
        } catch (TelegramException $e) {
            $this->error("Неуспешно: {$e->getMessage()}");
            $this->line('Провери, че ботът е добавен като администратор в канала.');

            return self::FAILURE;
        }

        $this->info("chat_id на {$username}: {$chatId}");
        $this->newLine();
        $this->line('Добави в .env на сървъра:');
        $this->line("TELEGRAM_CHAT_ID={$chatId}");
        $this->newLine();
        $this->comment('Ползвай числото, не @username — при преименуване на канала username-ът се сменя и постовете спират тихо.');

        return self::SUCCESS;
    }
}
