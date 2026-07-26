<?php

declare(strict_types=1);

namespace App\Services\Telegram\Formatters;

use App\Models\TeamNewsItem;
use App\Services\Telegram\TelegramText;

/**
 * Съставя поста за новина.
 *
 * Кратко нарочно: заглавие, резюме и линк. Пълната статия живее на сайта, а
 * превюто на линка носи заглавната снимка — затова постът не повтаря
 * съдържанието, а подканва да се отвори.
 */
class NewsFormatter
{
    /** Над този праг новината получава по-силен акцент. */
    private const HISTORIC_IMPORTANCE = 5;

    public function format(TeamNewsItem $item): string
    {
        $item->loadMissing('constructor');

        $historic = (int) $item->importance_score >= self::HISTORIC_IMPORTANCE;

        $lines = [
            ($historic ? '🚨' : '📰').' <b>'.TelegramText::escape($this->title($item)).'</b>',
        ];

        $summary = trim((string) $item->summary_bg);

        if ($summary !== '') {
            $lines[] = '';
            $lines[] = TelegramText::escape($summary);
        }

        $tags = $this->tags($item);

        if ($tags !== '') {
            $lines[] = '';
            $lines[] = "<i>{$tags}</i>";
        }

        $lines[] = '';
        $lines[] = '<a href="'.TelegramText::escape(route('news.show', $item->slug)).'">Прочети в Падок →</a>';

        return implode("\n", $lines);
    }

    /**
     * Българското заглавие; латинското е резервно, ако преводът е пропаднал.
     */
    private function title(TeamNewsItem $item): string
    {
        return trim((string) $item->title_bg) !== ''
            ? (string) $item->title_bg
            : (string) $item->title_original;
    }

    /**
     * „Ferrari · Пилот" — контекст с един поглед, без хаштагове.
     */
    private function tags(TeamNewsItem $item): string
    {
        return collect([
            $item->constructor?->name,
            $item->classification?->label(),
        ])
            ->filter()
            ->map(fn (string $value): string => TelegramText::escape($value))
            ->implode(' · ');
    }
}
