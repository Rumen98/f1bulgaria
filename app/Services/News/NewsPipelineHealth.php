<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Enums\NewsStatus;
use App\Models\TeamNewsItem;
use Illuminate\Support\Carbon;

/**
 * Преценява дали news pipeline-ът още произвежда.
 *
 * Защо съществува: на 03-04.09.2026 доставчикът на LLM спря да сервира
 * модела и всяка заявка връщаше 403. NewsEnricher хваща изключението за
 * всеки елемент, пише warning и оставя реда `pending` — командата връща
 * SUCCESS, cron изглежда здрав, sitemap-ът се обновява, сайтът е онлайн.
 * Пайплайнът мълча 24 часа и никой не разбра.
 *
 * Проверката е нарочно косвена — гледа РЕЗУЛТАТА, а не конкретна грешка.
 * Така хваща и причини, които още не сме виждали: изчерпан кредит, сменен
 * модел, счупен ключ, мъртъв cron, пълен диск.
 */
class NewsPipelineHealth
{
    /**
     * Колко часа чака най-старият необработен елемент, преди да алармираме.
     *
     * Мери се възрастта на ГЛАВАТА на опашката, а не „кога сме публикували
     * последно". Причината е конкретна: `news:normalize-bg` пипа вече
     * публикувани редове и им вдига `updated_at`, без изобщо да минава през
     * LLM. Проверка върху `updated_at` следователно може да покаже жив
     * pipeline, докато обогатяването е мъртво — точно заблудата, срещу която
     * е този клас.
     *
     * news:enrich минава по 25 елемента на :05/:35, тоест ~50 на час. Три
     * часа чакане на главата на опашката е недвусмислено засядане; нормално
     * натрупване се източва много преди това.
     */
    private const STALE_ENRICH_HOURS = 3;

    /**
     * Колко часа без нов вписан елемент значат, че вземането е спряло.
     *
     * По-дълъг прозорец: източниците наистина затихват нощем и в паузите
     * между състезания, а фалшива тревога обезсмисля алармата.
     */
    private const STALE_FETCH_HOURS = 12;

    /**
     * @return array{healthy:bool, reason:?string, pending:int, oldest_pending_at:?string, last_fetched_at:?string, stale_hours:?int}
     */
    public function check(): array
    {
        $awaiting = TeamNewsItem::query()
            ->where('status', NewsStatus::Pending->value)
            ->where(function ($query) {
                $query->whereNull('title_bg')->orWhereNull('classification');
            });

        $pending = (clone $awaiting)->count();
        $oldestPending = (clone $awaiting)->min('created_at');
        $lastFetched = TeamNewsItem::query()->max('created_at');

        $oldestPendingAt = $oldestPending ? Carbon::parse($oldestPending) : null;
        $lastFetchedAt = $lastFetched ? Carbon::parse($lastFetched) : null;

        $result = [
            'healthy' => true,
            'reason' => null,
            'pending' => $pending,
            'oldest_pending_at' => $oldestPendingAt?->toDateTimeString(),
            'last_fetched_at' => $lastFetchedAt?->toDateTimeString(),
            'stale_hours' => null,
        ];

        // Празна база (нов инсталационен профил) не е авария.
        if ($lastFetchedAt === null) {
            return $result;
        }

        // Обогатяването е засякло: главата на опашката чака твърде дълго.
        // Това е формата, която аварията от 03.09 прие.
        if ($oldestPendingAt !== null && $oldestPendingAt->diffInHours(now()) >= self::STALE_ENRICH_HOURS) {
            $waiting = (int) $oldestPendingAt->diffInHours(now());

            return [
                ...$result,
                'healthy' => false,
                'reason' => 'Обогатяването е засякло: '.$pending.' чакащи новини, най-старата стои от '
                    .$waiting.' ч. (от '.$oldestPendingAt->toDateTimeString().').',
                'stale_hours' => $waiting,
            ];
        }

        // Вземането е спряло: нищо ново не влиза. Тук опашката е празна,
        // което при горната проверка изглежда здраво — затова е отделна.
        if ($lastFetchedAt->diffInHours(now()) >= self::STALE_FETCH_HOURS) {
            return [
                ...$result,
                'healthy' => false,
                'reason' => 'Вземането е спряло: няма нова новина от '.$lastFetchedAt->diffForHumans().'.',
                'stale_hours' => (int) $lastFetchedAt->diffInHours(now()),
            ];
        }

        return $result;
    }
}
