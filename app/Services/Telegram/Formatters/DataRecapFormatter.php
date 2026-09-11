<?php

declare(strict_types=1);

namespace App\Services\Telegram\Formatters;

use App\Models\RaceDataRecap;
use App\Services\Telegram\TelegramText;

/**
 * Постът с данните в канала.
 *
 * Кратък нарочно: ChannelPublisher редактира вече изпратено съобщение само ако
 * то се събира в един къс. Дълъг пост значи, че всяка следваща поправка ще
 * произведе НОВО съобщение вместо редакция — и каналът се напълва с дубликати.
 * Тук стоят пет реда и линк; графиките са на сайта.
 */
class DataRecapFormatter
{
    private const VISIBLE_FACTS = 5;

    public function format(RaceDataRecap $recap): string
    {
        $race = $recap->race;

        if ($race === null) {
            return '';
        }

        $lines = [
            '📊 <b>Данните от '.TelegramText::escape($race->name_bg).'</b>',
        ];

        if ($race->round !== null) {
            $lines[] = TelegramText::escape("кръг {$race->round}");
        }

        $lines[] = '';

        foreach (array_slice((array) ($recap->facts['bullets'] ?? []), 0, self::VISIBLE_FACTS) as $fact) {
            $lines[] = '• '.TelegramText::escape((string) $fact);
        }

        $lines[] = '';
        $lines[] = '<a href="'.TelegramText::escape(route('racedata.show', $race->id)).'">Графиките в Падок</a>';
        $lines[] = '<i>'.TelegramText::escape((string) config('channel.openf1_attribution')).'</i>';

        return implode("\n", $lines);
    }
}
