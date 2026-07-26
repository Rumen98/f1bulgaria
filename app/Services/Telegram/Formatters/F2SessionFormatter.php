<?php

declare(strict_types=1);

namespace App\Services\Telegram\Formatters;

use App\Enums\F2SessionType;
use App\Models\F2RaceSession;
use App\Models\F2Result;
use App\Services\Telegram\TelegramText;
use Illuminate\Support\Collection;

/**
 * Съставя поста за F2 сесия.
 *
 * Публикуваме първите десет плюс линк към сайта, а не цялата класация: двайсет
 * и два реда правят поста нечетим на телефон и приближават лимита от 4096
 * знака, а линкът връща трафик към padok.bg.
 */
class F2SessionFormatter
{
    private const VISIBLE_POSITIONS = 10;

    private const MEDALS = [1 => '🥇', 2 => '🥈', 3 => '🥉'];

    public function format(F2RaceSession $session): string
    {
        $session->loadMissing(['race.season', 'results.driver.team']);

        $race = $session->race;
        $type = $session->session_type;

        $lines = [];
        $lines[] = '🏁 <b>Формула 2 · '.TelegramText::escape($type->label()).'</b>';

        $subtitle = collect([
            $race?->country_name ?: $race?->location_name,
            $race?->round !== null ? "кръг {$race->round}" : null,
        ])->filter()->implode(' · ');

        if ($subtitle !== '') {
            $lines[] = TelegramText::escape($subtitle);
        }

        $lines[] = '';

        foreach ($this->classified($session)->take(self::VISIBLE_POSITIONS) as $result) {
            $lines[] = $this->resultLine($result, $type);
        }

        $retired = $this->retired($session);

        if ($retired->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '<i>Отпаднали: '.TelegramText::escape($retired->implode(', ')).'</i>';
        }

        if ($session->version === 'Provisional') {
            $lines[] = '';
            $lines[] = '<i>Класацията е временна — стюардите още не са се произнесли.</i>';
        }

        $url = $this->url($session);

        if ($url !== null) {
            $lines[] = '';
            $lines[] = '<a href="'.TelegramText::escape($url).'">Пълна класация в Падок</a>';
        }

        return implode("\n", $lines);
    }

    /**
     * @return Collection<int, F2Result>
     */
    private function classified(F2RaceSession $session): Collection
    {
        return $session->results
            ->filter(fn (F2Result $result): bool => $result->position !== null)
            ->sortBy('position')
            ->values();
    }

    /**
     * @return Collection<int, string>
     */
    private function retired(F2RaceSession $session): Collection
    {
        return $session->results
            ->filter(fn (F2Result $result): bool => $result->position === null)
            ->map(fn (F2Result $result): string => $result->driver?->fullName() ?? '')
            ->filter()
            ->values();
    }

    private function resultLine(F2Result $result, F2SessionType $type): string
    {
        $position = (int) $result->position;
        $prefix = self::MEDALS[$position] ?? str_pad((string) $position, 2, ' ', STR_PAD_LEFT).'.';

        $driver = TelegramText::escape($result->driver?->fullName() ?? '');
        $team = TelegramText::escape($result->driver?->team?->name ?? '');

        $parts = ["{$prefix} <b>{$driver}</b>"];

        if ($team !== '') {
            $parts[] = $team;
        }

        // Победителят в състезание показва време, останалите — изоставане.
        // В тренировка и квалификация всеки има абсолютна обиколка.
        $time = $result->time_or_gap;

        if ($time !== null && $time !== '') {
            $parts[] = TelegramText::escape($time);
        }

        $line = implode(' · ', $parts);

        if ($type->isRace() && (float) $result->points > 0) {
            $points = rtrim(rtrim(number_format((float) $result->points, 1, ',', ''), '0'), ',');
            $line .= ' · '.TelegramText::escape("{$points} т.");
        }

        return $line;
    }

    /**
     * Страница на сайта за тази сесия.
     *
     * Маршрутът `f2.race` приема само sprint и feature — за тренировка и
     * квалификация още няма страница, затова водим към календара на сезона.
     */
    private function url(F2RaceSession $session): ?string
    {
        $race = $session->race;

        if ($race === null) {
            return null;
        }

        return match ($session->session_type) {
            F2SessionType::SprintRace => route('f2.race', [$race->slug, 'sprint']),
            F2SessionType::FeatureRace => route('f2.race', [$race->slug, 'feature']),
            default => $race->season?->year !== null
                ? route('f2.calendar.year', $race->season->year)
                : route('f2.calendar'),
        };
    }
}
