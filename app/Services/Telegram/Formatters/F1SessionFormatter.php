<?php

declare(strict_types=1);

namespace App\Services\Telegram\Formatters;

use App\Enums\ChannelPostKind;
use App\Enums\ResultSessionType;
use App\Enums\SessionType;
use App\Models\Driver;
use App\Models\Race;
use App\Models\Result;
use App\Models\SessionResult;
use App\Services\Races\RaceNameLocalizer;
use App\Services\Telegram\TelegramText;
use App\Support\DriverName;
use Illuminate\Support\Collection;

/**
 * Съставя поста за сесия от Формула 1.
 *
 * Състезанието и спринтът четат от `results` (там са точките), а
 * квалификацията — от `session_results`. Двете таблици са разделени нарочно;
 * виж миграцията на `session_results`.
 */
class F1SessionFormatter
{
    private const VISIBLE_POSITIONS = 10;

    private const MEDALS = [1 => '🥇', 2 => '🥈', 3 => '🥉'];

    public function __construct(
        private readonly RaceNameLocalizer $raceNames,
    ) {}

    public function format(Race $race, ChannelPostKind $kind): string
    {
        $lines = [
            '🏁 <b>Формула 1 · '.TelegramText::escape($kind->label()).'</b>',
            TelegramText::escape($this->subtitle($race)),
            '',
        ];

        $sessionType = $kind->sessionType();

        $lines = [...$lines, ...($sessionType !== null
            ? $this->sessionResultLines($race, $sessionType)
            : $this->raceLines($race, $kind)
        )];

        $lines = [...$lines, ...$this->context($race, $kind)];

        $lines[] = '';
        $lines[] = '<a href="'.TelegramText::escape(route('races.show', $race)).'">Пълна класация в Падок</a>';

        // CC BY-NC-SA изисква посочване на източника — това не е любезност,
        // а условие на лиценза, при който ползваме данните за тренировките.
        if ($kind->requiresOpenF1Attribution()) {
            $lines[] = '<i>'.TelegramText::escape((string) config('channel.openf1_attribution')).'</i>';
        }

        return implode("\n", $lines);
    }

    private function subtitle(Race $race): string
    {
        return collect([
            $this->raceNames->localize($race->jolpica_id, $race->name),
            $race->round !== null ? "кръг {$race->round}" : null,
        ])->filter()->implode(' · ');
    }

    /**
     * @return array<int, string>
     */
    private function raceLines(Race $race, ChannelPostKind $kind): array
    {
        $type = $kind === ChannelPostKind::F1Sprint
            ? ResultSessionType::Sprint
            : ResultSessionType::Race;

        $results = Result::query()
            ->where('race_id', $race->id)
            ->where('session_type', $type->value)
            ->with('driver.constructor')
            ->get();

        $lines = [];

        foreach ($this->classified($results)->take(self::VISIBLE_POSITIONS) as $result) {
            $name = TelegramText::escape($this->name($result->driver));
            $team = TelegramText::escape($result->driver?->constructor?->name ?? '');

            $parts = [$this->prefix((int) $result->position)." <b>{$name}</b>"];

            if ($team !== '') {
                $parts[] = $team;
            }

            if ((float) $result->points > 0) {
                $parts[] = TelegramText::escape($this->points((float) $result->points).' т.');
            }

            $line = implode(' · ', $parts);

            if ($result->fastest_lap) {
                $line .= ' 🟣';
            }

            $lines[] = $line;
        }

        $retired = $results
            ->filter(fn (Result $r): bool => $r->dnf)
            ->map(fn (Result $r): string => $this->name($r->driver))
            ->filter();

        if ($retired->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '<i>Отпаднали: '.TelegramText::escape($retired->implode(', ')).'</i>';
        }

        return $lines;
    }

    /**
     * Класация от сесия без точки: квалификация, спринт квалификация,
     * тренировки. Показва време на обиколка, не точки.
     *
     * @return array<int, string>
     */
    private function sessionResultLines(Race $race, SessionType $type): array
    {
        $results = SessionResult::query()
            ->where('race_id', $race->id)
            ->where('session_type', $type->value)
            ->with('driver.constructor')
            ->get();

        $lines = [];

        foreach ($this->classified($results)->take(self::VISIBLE_POSITIONS) as $result) {
            $name = TelegramText::escape($this->name($result->driver));
            $team = TelegramText::escape($result->driver?->constructor?->name ?? '');

            $parts = [$this->prefix((int) $result->position)." <b>{$name}</b>"];

            if ($team !== '') {
                $parts[] = $team;
            }

            // При квалификация показваме отсечката, до която пилотът е стигнал
            // (Q3, иначе Q2, иначе Q1) — иначе редовете носят времена от
            // различни етапи и подредбата изглежда сгрешена.
            $time = $result->bestQualifyingTime();

            if (filled($time)) {
                $parts[] = TelegramText::escape((string) $time);
            }

            // Изоставането има смисъл само след лидера.
            if ((int) $result->position > 1 && filled($result->gap)) {
                $parts[] = TelegramText::escape((string) $result->gap);
            }

            $lines[] = implode(' · ', $parts);
        }

        return $lines;
    }

    /**
     * @return array<int, string>
     */
    private function context(Race $race, ChannelPostKind $kind): array
    {
        if ($kind !== ChannelPostKind::F1Race || $race->poleDriver === null) {
            return [];
        }

        return [
            '',
            '🅿️ От пол позиция: <b>'.TelegramText::escape($this->name($race->poleDriver)).'</b>',
        ];
    }

    /**
     * @param  Collection<int, Result|SessionResult>  $results
     * @return Collection<int, Result|SessionResult>
     */
    private function classified(Collection $results): Collection
    {
        return $results
            ->filter(fn ($result): bool => $result->position !== null)
            ->sortBy('position')
            ->values();
    }

    private function prefix(int $position): string
    {
        return self::MEDALS[$position] ?? str_pad((string) $position, 2, ' ', STR_PAD_LEFT).'.';
    }

    private function name(?Driver $driver): string
    {
        if ($driver === null) {
            return '';
        }

        return DriverName::display($driver->slug, $driver->fullName());
    }

    private function points(float $points): string
    {
        return rtrim(rtrim(number_format($points, 1, ',', ''), '0'), ',');
    }
}
