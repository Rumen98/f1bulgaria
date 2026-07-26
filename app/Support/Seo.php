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

    private ?int $imageWidth = null;

    private ?int $imageHeight = null;

    private string $type = 'website';

    private ?string $canonical = null;

    private ?string $publishedAt = null;

    private ?string $modifiedAt = null;

    /** @var array<int, array<string, mixed>> Допълнителни JSON-LD възли. */
    private array $schema = [];

    public const DEFAULT_TITLE = 'Падок — Формула 1 на български';

    public const DEFAULT_DESCRIPTION = 'Формула 1 на български — новини, календар на състезанията, класирания, статистика и прогнози.';

    /**
     * Нулира всичко. ЗАДЪЛЖИТЕЛНО в началото на всяка заявка: инстанцията е
     * scoped, а при long-lived runtime (Octane, FrankenPHP) контейнерът живее
     * между заявките. Без reset canonical от предишна статия изтича в следваща
     * страница — Google я приема за дубликат и я маха от индекса.
     */
    public function reset(): void
    {
        $this->title = null;
        $this->description = null;
        $this->image = null;
        $this->imageWidth = null;
        $this->imageHeight = null;
        $this->type = 'website';
        $this->canonical = null;
        $this->publishedAt = null;
        $this->modifiedAt = null;
        $this->schema = [];
    }

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

    /**
     * Размерите се обявяват САМО когато са известни. Facebook и LinkedIn
     * избират layout-а на картата по обявените размери, преди да са изтеглили
     * файла — грешно обявени 1200x630 върху портретна снимка дава жестоко
     * изрязано изображение, а първият scrape се кешира.
     */
    public function image(?string $url, ?int $width = null, ?int $height = null): self
    {
        $this->image = $url;
        $this->imageWidth = $width;
        $this->imageHeight = $height;

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

    /**
     * Размери само за собствения og-image.jpg (1200x630) или ако са подадени
     * изрично. За външни снимки връща null — по-добре без размери, отколкото
     * с грешни.
     *
     * @return array{0:int, 1:int}|null
     */
    public function resolvedImageSize(): ?array
    {
        if ($this->image === null) {
            return [1200, 630]; // дефолтният банер
        }

        return $this->imageWidth !== null && $this->imageHeight !== null
            ? [$this->imageWidth, $this->imageHeight]
            : null;
    }

    /**
     * summary_large_image само при известно широкоформатно изображение;
     * иначе портретна снимка на пилот се реже през лицето.
     */
    public function twitterCard(): string
    {
        return $this->resolvedImageSize() !== null ? 'summary_large_image' : 'summary';
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
