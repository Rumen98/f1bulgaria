<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Per-request SEO метаданни, рендерирани СЪРВЪРНО в app.blade.php.
 *
 * Причина да не живеят във Vue `<Head>`: Inertia head manager-ът работи само
 * в браузъра. Facebook, Viber, Telegram, X и LinkedIn не изпълняват JavaScript
 * — за тях единственият източник е първоначалният HTML. Контролерите пълнят
 * този обект, шаблонът го чете.
 *
 * Регистриран е като scoped singleton, т.е. една инстанция на заявка.
 */
class Seo
{
    private ?string $title = null;

    private ?string $description = null;

    private ?string $image = null;

    private string $type = 'website';

    private ?string $canonical = null;

    private ?string $publishedAt = null;

    private ?string $modifiedAt = null;

    /** @var array<int, array<string, mixed>> Допълнителни JSON-LD възли. */
    private array $schema = [];

    public const DEFAULT_TITLE = 'Падок — Формула 1 на български';

    public const DEFAULT_DESCRIPTION = 'Формула 1 на български — новини, календар на състезанията, класирания, статистика и прогнози.';

    /**
     * Заглавие на страницата. Без суфикса на бранда — той се добавя тук.
     */
    public function title(?string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function description(?string $description): self
    {
        $this->description = $description !== null
            ? Str::limit(trim(preg_replace('/\s+/', ' ', $description) ?? ''), 200)
            : null;

        return $this;
    }

    public function image(?string $url): self
    {
        $this->image = $url;

        return $this;
    }

    /** og:type — 'website' или 'article'. */
    public function type(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function canonical(?string $url): self
    {
        $this->canonical = $url;

        return $this;
    }

    /** ISO 8601 дати за article og тагове и NewsArticle schema. */
    public function dates(?string $published, ?string $modified = null): self
    {
        $this->publishedAt = $published;
        $this->modifiedAt = $modified;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public function schema(array $node): self
    {
        $this->schema[] = $node;

        return $this;
    }

    /**
     * Заглавие за `<title>` — с брандов суфикс, освен ако вече е дълго.
     */
    public function resolvedTitle(): string
    {
        if ($this->title === null || $this->title === '') {
            return self::DEFAULT_TITLE;
        }

        return Str::length($this->title) > 55
            ? $this->title
            : $this->title.' — Падок';
    }

    /**
     * Заглавие за og:title / twitter:title — БЕЗ брандов суфикс.
     * В социалния фийд домейнът се показва отделно, а суфиксът само яде
     * от видимите знаци на самото заглавие.
     */
    public function socialTitle(): string
    {
        return $this->title ?: self::DEFAULT_TITLE;
    }

    public function resolvedDescription(): string
    {
        return $this->description ?: self::DEFAULT_DESCRIPTION;
    }

    public function resolvedImage(): string
    {
        return $this->image ?: asset('og-image.jpg');
    }

    public function resolvedType(): string
    {
        return $this->type;
    }

    /**
     * Канонично URL, независимо от хоста на заявката — иначе www.padok.bg
     * канонизира сам себе си и се получават два индексирани сайта.
     */
    public function resolvedCanonical(): string
    {
        if ($this->canonical !== null) {
            return $this->canonical;
        }

        $path = request()->getPathInfo();

        return rtrim((string) config('app.url'), '/').($path === '/' ? '' : $path);
    }

    public function publishedAt(): ?string
    {
        return $this->publishedAt;
    }

    public function modifiedAt(): ?string
    {
        return $this->modifiedAt;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function schemaNodes(): array
    {
        return $this->schema;
    }
}
