<?php

declare(strict_types=1);

namespace App\Services\Telegram;

/**
 * Грешка, която повтарянето няма да оправи: изгонен бот, липсващи права за
 * публикуване, невалиден токен, грешен chat_id или счупен HTML в текста.
 *
 * ВНИМАНИЕ при обработка: Telegram връща 403 „bot was kicked" И когато ботът
 * е още в канала, но му липсва право да публикува. Двата случая не се
 * различават от отговора на sendMessage, затова НЕ изключвай канала
 * автоматично при 403 — само алармирай.
 *
 * @see https://core.telegram.org/bots/api#making-requests
 */
class TelegramPermanentException extends TelegramException {}
