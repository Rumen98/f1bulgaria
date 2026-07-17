<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Models\Race;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Генерира iCalendar (RFC 5545) емисия от състезания и техните сесии — за
 * абониране в Google/Apple Calendar. Без външни пакети (прост текстов формат).
 */
class IcsCalendarBuilder
{
    private const DURATION_MINUTES = 90;

    /**
     * @param  Collection<int, Race>  $races  с заредени sessions
     */
    public function build(Collection $races, string $calendarName): string
    {
        $stamp = Carbon::now('UTC')->format('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Падок//Календар//BG',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->escape($calendarName),
            'X-WR-TIMEZONE:UTC',
        ];

        foreach ($races as $race) {
            foreach ($this->eventsForRace($race) as $event) {
                $lines = array_merge($lines, $this->vevent($event, $stamp));
            }
        }

        $lines[] = 'END:VCALENDAR';

        return $this->fold($lines)."\r\n";
    }

    /**
     * @return array<int, array{uid:string, start:Carbon, summary:string, description:string, location:string}>
     */
    private function eventsForRace(Race $race): array
    {
        $location = trim(implode(', ', array_filter([$race->circuit, $race->country])));
        $link = route('races.show', $race->id);
        $events = [];

        if ($race->sessions->isNotEmpty()) {
            foreach ($race->sessions as $session) {
                if ($session->scheduled_at_utc === null) {
                    continue;
                }

                $events[] = [
                    'uid' => "padok-{$race->id}-{$session->type->value}@padok.bg",
                    'start' => $session->scheduled_at_utc,
                    'summary' => "{$race->name} — {$session->type->label()}",
                    'description' => "{$race->circuit}, {$race->country}\\n{$link}",
                    'location' => $location,
                ];
            }

            return $events;
        }

        // Резерв: ако няма сесии — поне състезанието от race_datetime_utc.
        if ($race->race_datetime_utc !== null) {
            $events[] = [
                'uid' => "padok-{$race->id}-race@padok.bg",
                'start' => $race->race_datetime_utc,
                'summary' => "{$race->name} — Състезание",
                'description' => "{$race->circuit}, {$race->country}\\n{$link}",
                'location' => $location,
            ];
        }

        return $events;
    }

    /**
     * @param  array{uid:string, start:Carbon, summary:string, description:string, location:string}  $event
     * @return array<int, string>
     */
    private function vevent(array $event, string $stamp): array
    {
        $start = $event['start']->copy()->utc();
        $end = $start->copy()->addMinutes(self::DURATION_MINUTES);

        return [
            'BEGIN:VEVENT',
            'UID:'.$event['uid'],
            'DTSTAMP:'.$stamp,
            'DTSTART:'.$start->format('Ymd\THis\Z'),
            'DTEND:'.$end->format('Ymd\THis\Z'),
            'SUMMARY:'.$this->escape($event['summary']),
            'DESCRIPTION:'.$this->escape($event['description']),
            'LOCATION:'.$this->escape($event['location']),
            'CATEGORIES:F1',
            'END:VEVENT',
        ];
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', ',', ';'], ['\\\\', '\\,', '\\;'], $value);
    }

    /**
     * Сгъва редовете до ~73 символа (RFC 5545 line folding), безопасно за UTF-8
     * (по символи, не по байтове), и ги съединява с CRLF.
     *
     * @param  array<int, string>  $lines
     */
    private function fold(array $lines): string
    {
        $out = [];

        foreach ($lines as $line) {
            if (mb_strlen($line) <= 73) {
                $out[] = $line;

                continue;
            }

            $chunks = mb_str_split($line, 72);
            $out[] = array_shift($chunks);
            foreach ($chunks as $chunk) {
                $out[] = ' '.$chunk;
            }
        }

        return implode("\r\n", $out);
    }
}
