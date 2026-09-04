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
 * Пайплайнът мълча 24 часа и никой не разбра. Тук е сигналът, който
 * липсваше.
 *
 * Проверката е нарочно косвена — гледа РЕЗУЛТАТА (публикува ли се още), а
 * не конкретна грешка. Така хваща и причини, които още не сме виждали:
 * изчерпан кредит, сменен модел, счупен ключ, мъртъв cron, пълен диск.
 */
class NewsPipelineHealth
{
    /**
     * Колко часа без публикация приемаме за нормални, докато има чакащи.
     *
     * news:enrich върви на :05/:35, значи 3 часа са 6 пропуснати цикъла —
     * достатъчно, за да не вдига тревога при единичен таймаут, и
     * достатъчно бързо, за да не мълчи цяло денонощие.
     */
    private const STALE_PUBLISH_HOURS = 3;

    /**
     * Колко часа без нов вписан елемент значат, че вземането е спряло.
     *
     * По-дълъг прозорец от горния: източниците наистина затихват нощем и
     * в паузите между състезания, а фалшива тревога обезсмисля алармата.
     */
    private const STALE_FETCH_HOURS = 12;

    /**
     * @return array{healthy:bool, reason:?string, pending:int, last_published_at:?string, last_fetched_at:?string, stale_hours:?int}
     */
    public function check(): array
    {
        $pending = TeamNewsItem::query()
            ->where('status', NewsStatus::Pending->value)
            ->where(function ($query) {
                $query->whereNull('title_bg')->orWhereNull('classification');
            })
            ->count();

        $lastPublished = TeamNewsItem::query()
            ->whereIn('status', collect(NewsStatus::publiclyVisible())->map(fn (NewsStatus $s) => $s->value))
            ->max('updated_at');

        $lastFetched = TeamNewsItem::query()->max('created_at');

        $lastPublishedAt = $lastPublished ? Carbon::parse($lastPublished) : null;
        $lastFetchedAt = $lastFetched ? Carbon::parse($lastFetched) : null;

        $result = [
            'healthy' => true,
            'reason' => null,
            'pending' => $pending,
            'last_published_at' => $lastPublishedAt?->toDateTimeString(),
            'last_fetched_at' => $lastFetchedAt?->toDateTimeString(),
            'stale_hours' => null,
        ];

        // Празна база (нов инсталационен профил) не е авария.
        if ($lastFetchedAt === null) {
            return $result;
        }

        // Обогатяването е спряло: има какво да се обработи, но нищо не
        // излиза. Това е формата, която аварията от 03.09 прие.
        $publishStale = $lastPublishedAt === null
            || $lastPublishedAt->diffInHours(now()) >= self::STALE_PUBLISH_HOURS;

        if ($pending > 0 && $publishStale) {
            return [
                ...$result,
                'healthy' => false,
                'reason' => 'Обогатяването е спряло: '.$pending.' чакащи новини, а нищо не е публикувано от '
                    .($lastPublishedAt?->diffForHumans() ?? 'никога').'.',
                'stale_hours' => (int) ($lastPublishedAt?->diffInHours(now()) ?? 0),
            ];
        }

        // Вземането е спряло: нищо ново не влиза. Тук `pending` е 0, което
        // при горната проверка изглежда здраво — затова е отделна.
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
