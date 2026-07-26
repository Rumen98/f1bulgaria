<?php

declare(strict_types=1);

namespace App\Support;

/**
 * JSON-LD структурирани данни за търсачките.
 *
 * Живее в PHP клас (не в Blade), защото Blade компилира schema ключа
 * "(at)context" като своя едноименна директива — дори вътре в PHP стринг
 * в {!! !!} израз. Този бъг вече стигна до production веднъж; не връщай
 * schema markup обратно в шаблона. (Дори Pint яде този ключ в docblock.)
 */
class SeoSchema
{
    /**
     * @param  array<int, array<string, mixed>>  $extra  Възли за конкретната страница (напр. NewsArticle).
     */
    public static function jsonLd(array $extra = []): string
    {
        return (string) json_encode([
            '@context' => 'https://schema.org',
            '@graph' => [
                self::organization(),
                [
                    '@type' => 'WebSite',
                    '@id' => config('app.url').'/#website',
                    'name' => 'Падок',
                    'url' => config('app.url'),
                    'inLanguage' => 'bg',
                ],
                ...$extra,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>
     */
    public static function organization(): array
    {
        return [
            '@type' => 'Organization',
            '@id' => config('app.url').'/#organization',
            'name' => 'Падок',
            'url' => config('app.url'),
            'logo' => asset('icon-512.png'),
            'description' => 'Независима общност на българските фенове на Формула 1.',
        ];
    }

    /**
     * NewsArticle възел за страница на новина. Датите ТРЯБВА да са ISO 8601 —
     * свежестта е основен ранкинг сигнал при новинарските заявки.
     *
     * @param  array<int, string>  $keyFacts
     * @return array<string, mixed>
     */
    public static function newsArticle(
        string $headline,
        string $description,
        string $url,
        string $image,
        ?string $publishedAt,
        ?string $modifiedAt = null,
        array $keyFacts = [],
    ): array {
        $node = [
            '@type' => 'NewsArticle',
            'headline' => $headline,
            'description' => $description,
            'url' => $url,
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
            'image' => [$image],
            'inLanguage' => 'bg',
            'author' => ['@id' => config('app.url').'/#organization'],
            'publisher' => ['@id' => config('app.url').'/#organization'],
            'isAccessibleForFree' => true,
        ];

        if ($publishedAt !== null) {
            $node['datePublished'] = $publishedAt;
            $node['dateModified'] = $modifiedAt ?? $publishedAt;
        }

        if ($keyFacts !== []) {
            $node['alternativeHeadline'] = $keyFacts[0];
        }

        return $node;
    }

    /**
     * @param  array<int, array{name:string, url:string}>  $crumbs
     * @return array<string, mixed>
     */
    public static function breadcrumbs(array $crumbs): array
    {
        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(fn (array $c, int $i) => [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $c['name'],
                'item' => $c['url'],
            ], $crumbs, array_keys($crumbs)),
        ];
    }
}
