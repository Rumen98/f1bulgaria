<?php

declare(strict_types=1);

namespace App\Services\RaceData;

use App\Enums\NewsClassification;
use App\Enums\NewsStatus;
use App\Models\RaceDataRecap;
use App\Models\TeamNewsItem;
use App\Models\TeamNewsSource;

/**
 * Слага рекапа и в новинарската емисия — не защото там му е домът (домът е
 * /danni), а защото това е безплатната дистрибуция: RSS, sitemap, търсачката
 * на сайта и опашката към Telegram канала са закачени за `team_news_items` и
 * я получават даром.
 *
 * Статията е кратка нарочно: тя е анонс с линк към страницата с графиките, а
 * не второ копие на съдържанието.
 */
class RecapPublisher
{
    /**
     * Вътрешният „източник“, под който вървят собствените ни материали.
     * `team_news_sources` няма slug колона, затова ключът е feed_url.
     */
    public const SOURCE_FEED = '/danni';

    /**
     * Под прага на канала (config('channel.news_min_importance'), по
     * подразбиране 4). Рекапът си има собствен пост в канала със собствен
     * формат — ако мине и оттук, каналът получава два поста за едно нещо.
     */
    private const IMPORTANCE = 3;

    public function publish(RaceDataRecap $recap): ?TeamNewsItem
    {
        $race = $recap->race;

        if ($race === null || $recap->headline === null) {
            return null;
        }

        $source = $this->source();
        $url = route('racedata.show', $race->id);

        // external_url е UNIQUE — това дава идемпотентност даром: повторно
        // пускане обновява същата статия вместо да създаде втора.
        $item = TeamNewsItem::query()->firstOrNew(['external_url' => $url]);

        $item->fill([
            'source_id' => $source->id,
            'title_original' => $recap->headline,
            'title_bg' => $recap->headline,
            'summary_bg' => $this->summary($recap),
            'full_article_bg' => $recap->body_bg,
            'key_facts' => $recap->facts['bullets'] ?? null,
            'classification' => NewsClassification::Technical,
            'importance_score' => self::IMPORTANCE,
            'status' => NewsStatus::AutoPublished,
            'is_f1_related' => true,
            'featured_image' => $this->image($recap),
            'published_at' => $recap->generated_at ?? now(),
        ]);

        $item->save();

        $recap->forceFill(['news_item_id' => $item->id])->save();

        return $item;
    }

    private function summary(RaceDataRecap $recap): string
    {
        $bullets = (array) ($recap->facts['bullets'] ?? []);

        return $bullets === []
            ? 'Данните от състезанието — обиколка по обиколка.'
            : implode(' ', array_slice($bullets, 0, 2));
    }

    /**
     * Очертанието на пистата е най-подходящата корица: рекапът е за
     * състезанието, не за пилот или отбор.
     *
     * @return array<string, mixed>
     */
    private function image(RaceDataRecap $recap): array
    {
        $race = $recap->race;

        if ($race?->jolpica_id !== null) {
            return ['type' => 'circuit_outline', 'data' => [
                'slug' => $race->jolpica_id,
                'name' => $race->circuit,
            ]];
        }

        return ['type' => 'generic', 'data' => [
            'classification' => NewsClassification::Technical->value,
            'color' => NewsClassification::Technical->color(),
            'label' => NewsClassification::Technical->label(),
        ]];
    }

    /**
     * Вътрешният източник. Създава се при нужда, защото deploy.sh не пуска
     * сийдъри — разчитане на сийдър тук значи мълчалив провал на прода.
     *
     * is_active=false, за да не го обхожда четецът на RSS: този „източник“
     * няма емисия, ние пишем в него.
     */
    private function source(): TeamNewsSource
    {
        return TeamNewsSource::query()->firstOrCreate(
            ['feed_url' => url(self::SOURCE_FEED)],
            [
                'name' => 'Падок · данни',
                'language' => 'bg',
                'is_active' => false,
            ],
        );
    }
}
