<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Models\TeamNewsSource;
use Carbon\CarbonImmutable;
use FeedIo\Feed;
use FeedIo\Feed\ItemInterface;
use FeedIo\FeedIo;
use FeedIo\Reader\Document;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Взема и парсва RSS/Atom feed на даден източник.
 *
 * Самото HTTP вземане минава през Laravel HTTP клиента (15s timeout, до 3 опита
 * с exponential backoff само при 5xx/мрежови грешки — 4xx не се повтаря).
 * Парсването е чрез feed-io (авто-разпознаване RSS vs Atom).
 */
class RssFetcher
{
    private const TIMEOUT_SECONDS = 15;

    private const MAX_ATTEMPTS = 3;

    private const SNIPPET_LENGTH = 500;

    public function __construct(private readonly FeedIo $feedIo = new FeedIo) {}

    /**
     * @return Collection<int, ParsedFeedItem>
     *
     * @throws NewsFetchException
     */
    public function fetch(TeamNewsSource $source): Collection
    {
        $xml = $this->download($source->feed_url);

        return $this->parse($xml, $source->feed_url);
    }

    /**
     * @throws NewsFetchException
     */
    private function download(string $url): string
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->retry(
                    self::MAX_ATTEMPTS,
                    fn (int $attempt): int => (int) (200 * (2 ** ($attempt - 1))),
                    fn (Throwable $e): bool => $e instanceof ConnectionException
                        || ($e instanceof RequestException && $e->response->serverError()),
                    throw: true,
                )
                ->get($url);
        } catch (RequestException $e) {
            throw new NewsFetchException(
                "Feed върна {$e->response->status()} за {$url}.",
                previous: $e,
            );
        } catch (ConnectionException $e) {
            throw new NewsFetchException("Мрежова грешка при {$url}: {$e->getMessage()}", previous: $e);
        }

        return $response->body();
    }

    /**
     * @return Collection<int, ParsedFeedItem>
     *
     * @throws NewsFetchException
     */
    private function parse(string $xml, string $url): Collection
    {
        try {
            $feed = $this->feedIo->getReader()->parseDocument(new Document($xml), new Feed);
        } catch (Throwable $e) {
            throw new NewsFetchException("Невалиден feed XML от {$url}: {$e->getMessage()}", previous: $e);
        }

        $items = new Collection;

        /** @var ItemInterface $item */
        foreach ($feed as $item) {
            $link = $item->getLink();

            // Без линк не можем нито да дедупликираме, нито да съхраним елемента.
            if (blank($link)) {
                continue;
            }

            $items->push(new ParsedFeedItem(
                externalUrl: $link,
                externalGuid: $item->getPublicId() ?: null,
                titleOriginal: (string) $item->getTitle(),
                contentSnippet: $this->snippet($item),
                publishedAt: $this->publishedAt($item),
            ));
        }

        return $items;
    }

    private function snippet(ItemInterface $item): ?string
    {
        $raw = $item->getContent() ?: $item->getSummary();

        if (blank($raw)) {
            return null;
        }

        $plain = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5));

        return Str::limit($plain, self::SNIPPET_LENGTH, '');
    }

    private function publishedAt(ItemInterface $item): CarbonImmutable
    {
        $date = $item->getLastModified();

        return $date !== null
            ? CarbonImmutable::instance($date)->utc()
            : CarbonImmutable::now();
    }
}
