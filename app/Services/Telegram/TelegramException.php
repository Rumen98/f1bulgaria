<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use Exception;

/**
 * Временна грешка при работа с Telegram Bot API — мрежа, 5xx или изчерпан
 * rate limit. Редът в `channel_posts` остава pending и се пробва пак.
 */
class TelegramException extends Exception {}
