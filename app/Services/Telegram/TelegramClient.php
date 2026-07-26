<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Тънък HTTP wrapper над Telegram Bot API (без пакет — ползваме един метод).
 *
 * Форматирането е HTML, НЕ MarkdownV2. MarkdownV2 изисква escape на 18 знака,
 * сред които `.`, `+`, `-`, `(`, `)` — тоест всяко време („1:23.456"), всяка
 * разлика („+1.234s") и всяко тире в име на пилот. Един пропуснат escape
 * връща 400 и целият пост пада. HTML иска само `<`, `>` и `&`, които в
 * резултати не се срещат.
 *
 * Retry: 5xx и мрежови грешки — exponential backoff; 429 — точно толкова,
 * колкото каже `parameters.retry_after` (без множител отгоре), но само ако е
 * под MAX_RETRY_AFTER_SECONDS. По-дългите изчаквания се оставят на следващото
 * пускане на планировчика, вместо да държат worker-а зает. Всички останали
 * 4xx са постоянни и се хвърлят веднага.
 *
 * Токенът стои в ПЪТЯ на URL-а, затова никога не влиза в съобщение за грешка.
 *
 * @see https://core.telegram.org/bots/api#sendmessage
 * @see https://core.telegram.org/bots/api#responseparameters
 */
class TelegramClient
{
    private const DEFAULT_TIMEOUT_SECONDS = 15;

    private const MAX_ATTEMPTS = 3;

    /**
     * Над това изчакване при 429 не блокираме, а връщаме временна грешка —
     * flood wait от Telegram може да е стотици секунди.
     */
    private const MAX_RETRY_AFTER_SECONDS = 30;

    public function hasCredentials(): bool
    {
        return filled(config('services.telegram.bot_token'))
            && filled(config('services.telegram.chat_id'));
    }

    /**
     * Публикува съобщение в канала и връща message_id.
     *
     * @param  string  $html  Готов HTML — подадените стойности вече трябва да са
     *                        минали през TelegramText::escape().
     * @param  bool  $silent  Без звук при получаване (за по-маловажни постове).
     *
     * @throws TelegramException
     * @throws TelegramPermanentException
     */
    public function send(string $html, bool $silent = false): int
    {
        $chatId = config('services.telegram.chat_id');

        if (blank($chatId)) {
            throw new TelegramPermanentException('Липсва TELEGRAM_CHAT_ID в конфигурацията.');
        }

        $data = $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $html,
            'parse_mode' => 'HTML',
            // disable_web_page_preview е премахнат в Bot API 7.0 (дек. 2023) —
            // подаването му днес няма ефект.
            'link_preview_options' => ['is_disabled' => true],
            'disable_notification' => $silent,
        ]);

        return (int) data_get($data, 'message_id', 0);
    }

    /**
     * Пренаписва вече публикувано съобщение.
     *
     * Ползва се, когато временната класация стане окончателна: редакцията на
     * място не праща ново известие и не оставя два поста за едно събитие.
     *
     * @throws TelegramException
     * @throws TelegramPermanentException
     */
    public function edit(int $messageId, string $html): void
    {
        $chatId = config('services.telegram.chat_id');

        if (blank($chatId)) {
            throw new TelegramPermanentException('Липсва TELEGRAM_CHAT_ID в конфигурацията.');
        }

        try {
            $this->call('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $html,
                'parse_mode' => 'HTML',
                'link_preview_options' => ['is_disabled' => true],
            ]);
        } catch (TelegramPermanentException $e) {
            // Telegram отказва редакция с идентичен текст. Това означава, че
            // съобщението вече е каквото трябва — успех, не грешка.
            if (str_contains($e->getMessage(), 'message is not modified')) {
                return;
            }

            throw $e;
        }
    }

    /**
     * Вади числовото id на канал по @username. Ползва се веднъж при настройка
     * (`channel:resolve-chat-id`), не в горещия път.
     *
     * @throws TelegramException
     * @throws TelegramPermanentException
     */
    public function resolveChatId(string $username): int
    {
        $data = $this->call('getChat', [
            'chat_id' => Str::start($username, '@'),
        ]);

        $id = (int) data_get($data, 'id', 0);

        if ($id === 0) {
            throw new TelegramPermanentException("Telegram не върна id за {$username}.");
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws TelegramException
     * @throws TelegramPermanentException
     */
    private function call(string $method, array $payload): array
    {
        /** @var array{bot_token:?string, chat_id:?string, base_url:string, timeout?:int} $config */
        $config = config('services.telegram');

        if (blank($config['bot_token'])) {
            throw new TelegramPermanentException('Липсва TELEGRAM_BOT_TOKEN в конфигурацията.');
        }

        try {
            $response = Http::baseUrl(rtrim($config['base_url'], '/'))
                ->asJson()
                ->timeout((int) ($config['timeout'] ?? self::DEFAULT_TIMEOUT_SECONDS))
                ->retry(self::MAX_ATTEMPTS, $this->backoff(...), $this->shouldRetry(...), throw: true)
                ->post("/bot{$config['bot_token']}/{$method}", $payload);
        } catch (RequestException $e) {
            throw $this->translate($e);
        } catch (ConnectionException) {
            throw new TelegramException('Мрежова грешка при връзка с Telegram Bot API.');
        }

        /** @var array<string, mixed> $body */
        $body = (array) $response->json();

        // ok:false с HTTP 200 не се очаква, но Bot API не го изключва изрично.
        if (($body['ok'] ?? false) !== true) {
            $description = (string) ($body['description'] ?? 'без описание');

            throw new TelegramPermanentException("Telegram отказа {$method}: {$description}");
        }

        return (array) ($body['result'] ?? []);
    }

    /**
     * Превежда HTTP грешката в постоянна или временна.
     *
     * Разделяме по КЛАС НА СТАТУСА, не по текста на `description` —
     * документацията изрично казва, че съдържанието на error_code подлежи на
     * промяна, а списък с описанията изобщо не се публикува.
     */
    private function translate(RequestException $e): TelegramException
    {
        $status = $e->response->status();
        $description = (string) data_get($e->response->json(), 'description', '');
        $detail = $description !== '' ? ' '.Str::limit($description, 200) : '';

        if ($status === 429) {
            $seconds = $this->retryAfterSeconds($e->response);

            return new TelegramException("Telegram rate limit — изчакване {$seconds}s.");
        }

        if ($e->response->serverError()) {
            return new TelegramException("Telegram върна {$status}.{$detail}");
        }

        return new TelegramPermanentException("Telegram върна {$status}.{$detail}");
    }

    /**
     * Изчакване в милисекунди преди следващия опит.
     */
    private function backoff(int $attempt, Throwable $exception): int
    {
        if ($exception instanceof RequestException && $exception->response->status() === 429) {
            // Точно колкото каза Telegram — exponential отгоре само удължава
            // излишно и пак опира в същия лимит.
            return $this->retryAfterSeconds($exception->response) * 1000;
        }

        return 250 * (2 ** ($attempt - 1));
    }

    /**
     * Повтаряме при мрежа, 5xx и кратък 429. Дълъг 429 и всички други 4xx — не.
     */
    private function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        if ($exception->response->status() === 429) {
            return $this->retryAfterSeconds($exception->response) <= self::MAX_RETRY_AFTER_SECONDS;
        }

        return $exception->response->serverError();
    }

    /**
     * Изчакването живее в ТЯЛОТО (`parameters.retry_after`), не в Retry-After
     * хедър — хедърът не е документиран и не бива да се разчита на него.
     */
    private function retryAfterSeconds(Response $response): int
    {
        return max(1, (int) data_get($response->json(), 'parameters.retry_after', 1));
    }
}
