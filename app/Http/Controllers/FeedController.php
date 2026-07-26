<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\NewsStatus;
use App\Models\TeamNewsItem;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/**
 * RSS 2.0 фийд с последните публикувани новини.
 *
 * Безплатен дистрибуционен канал: Feedly и подобни агрегатори, Telegram RSS
 * ботове, IFTTT/Zapier авто-постване към социални мрежи.
 */
class FeedController extends Controller
{
    private const LIMIT = 30;

    public function __invoke(): Response
    {
        $items = TeamNewsItem::query()
            ->whereIn('status', collect(NewsStatus::publiclyVisible())->map->value->all())
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->limit(self::LIMIT)
            ->get();

        return response($this->buildXml($items), 200, [
            'Content-Type' => 'application/rss+xml; charset=UTF-8',
        ]);
    }

    /**
     * @param  Collection<int, TeamNewsItem>  $items
     */
    private function buildXml(Collection $items): string
    {
        $entries = $items->map(function (TeamNewsItem $item): string {
            $url = route('news.show', $item->slug);
            $title = $this->escape($item->title_bg ?? $item->title_original ?? '');
            $summary = $this->escape($item->summary_bg ?? $item->content_snippet ?? '');

            return implode("\n", [
                '    <item>',
                "      <title>{$title}</title>",
                '      <link>'.$this->escape($url).'</link>',
                '      <guid isPermaLink="true">'.$this->escape($url).'</guid>',
                "      <description>{$summary}</description>",
                '      <pubDate>'.$item->published_at?->toRfc2822String().'</pubDate>',
                $item->classification !== null
                    ? '      <category>'.$this->escape($item->classification->label()).'</category>'
                    : '',
                '    </item>',
            ]);
        })->implode("\n");

        $self = route('feed');
        $home = rtrim((string) config('app.url'), '/');
        $updated = $items->first()?->published_at?->toRfc2822String() ?? now()->toRfc2822String();

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
              <channel>
                <title>Падок — новини от Формула 1</title>
                <link>{$home}</link>
                <atom:link href="{$self}" rel="self" type="application/rss+xml" />
                <description>Последните новини от Формула 1 на български.</description>
                <language>bg</language>
                <lastBuildDate>{$updated}</lastBuildDate>
            {$entries}
              </channel>
            </rss>
            XML;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
