<?php

declare(strict_types=1);

namespace App\Services\F2;

use Illuminate\Support\Collection;

/**
 * Парсва wikitext на F2 страници в структурирани данни. Защитно: липсващи полета
 * → null; никога не измисля данни. Базиран на реалната структура на Wikipedia
 * (виж tests/Fixtures/f2/*).
 */
class WikitextParser
{
    private const STATUS_TOKENS = ['DNF', 'RET', 'DSQ', 'DQ', 'NC', 'EX', 'DNS', 'DNQ', 'WD'];

    /**
     * Парсва страница на кръг → спринт + главно състезание.
     *
     * @return array{round_no:?int, pole_driver:?string, sprint:array<string,mixed>, feature:array<string,mixed>}
     */
    public function parseRoundPage(string $wikitext): array
    {
        // Sprint = състезание 1 от уикенда (Date_r1), Feature = състезание 2 (Date_r2).
        return [
            'round_no' => $this->intOrNull($this->infoboxField($wikitext, 'Round_No')),
            'pole_driver' => $this->firstLink($this->poleField($wikitext) ?? ''),
            'sprint' => $this->parseSession($wikitext, 'Sprint race', $this->infoboxField($wikitext, 'Date_r1')),
            'feature' => $this->parseSession($wikitext, 'Feature race', $this->infoboxField($wikitext, 'Date_r2')),
        ];
    }

    /**
     * @return array{results:Collection<int,array<string,mixed>>, fastest_driver:?string, fastest_time:?string, date:?string}
     */
    private function parseSession(string $wikitext, string $heading, ?string $date = null): array
    {
        $section = $this->extractSection($wikitext, $heading);
        $fastest = $this->fastestLap($section);

        return [
            'results' => $this->parseResultsTable($section),
            'fastest_driver' => $fastest['driver'],
            'fastest_time' => $fastest['time'],
            'date' => $date !== null ? trim($this->stripWiki($date)) ?: null : null,
        ];
    }

    /**
     * Парсва wikitable с резултати → колекция от редове.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function parseResultsTable(string $section): Collection
    {
        $table = $this->firstTable($section);

        if ($table === null) {
            return collect();
        }

        $rows = collect();

        foreach (preg_split('/\n\|-/', $table) as $block) {
            $cells = $this->rowCells($block);

            if (count($cells) < 8) {
                continue; // header / footer / непълни редове
            }

            $posToken = trim($this->stripWiki($cells[0]));

            if (! $this->isPositionToken($posToken)) {
                continue; // заглавен ред (Pos.) или друго
            }

            $rows->push($this->mapRow($cells, $posToken));
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $cells
     * @return array<string, mixed>
     */
    private function mapRow(array $cells, string $posToken): array
    {
        $isNumeric = ctype_digit($posToken);
        $driverCell = $cells[2];
        $timeOrGap = trim($this->stripWiki($cells[5])) ?: null;

        return [
            'position' => $isNumeric ? (int) $posToken : null,
            'status' => $isNumeric ? 'Finished' : ($timeOrGap ?: strtoupper($posToken)),
            'car_number' => $this->intOrNull($this->stripWiki($cells[1])),
            'driver' => $this->displayName($driverCell),
            'driver_flag' => $this->flag($driverCell),
            'team' => $this->displayName($cells[3]),
            'laps' => $this->intOrNull($this->stripWiki($cells[4])),
            'time_or_gap' => $timeOrGap,
            'grid' => $this->intOrNull($this->stripWiki($cells[6])),
            'points' => $this->parsePoints($this->stripWiki($cells[7])),
        ];
    }

    /**
     * Клетките на един ред (всяка клетка → стойност без атрибути).
     *
     * @return array<int, string>
     */
    private function rowCells(string $block): array
    {
        $cells = [];

        foreach (explode("\n", $block) as $line) {
            $line = rtrim($line);
            if ($line === '' || str_starts_with($line, '{|') || str_starts_with($line, '|}')) {
                continue;
            }
            // Само истински caption `|+` (intervala/текст), но НЕ клетка-стойност като `|+5 Laps` / `|+1 Lap`.
            // Caption-ите се срещат само в table-open блока, който и без това отпада (липсват 8 клетки).
            if (str_starts_with($line, '|+') && ! preg_match('/^\|\+\s*[+\d]/', $line)) {
                continue;
            }

            if (str_starts_with($line, '!') || str_starts_with($line, '|')) {
                // colspan footer (Fastest lap / Source) → не е резултатен ред
                if (preg_match('/colspan\s*=/i', $line)) {
                    return [];
                }

                // няколко клетки на ред с || (рядко за F2, но защитно)
                $marker = $line[0];
                $body = ltrim(substr($line, 1));
                foreach (preg_split('/\|\|/', $body) as $part) {
                    $cells[] = $this->cellValue($marker.$part);
                }
            }
        }

        return $cells;
    }

    /**
     * Маха атрибутите на клетка (напр. `align="center" |23` → `23`).
     */
    private function cellValue(string $raw): string
    {
        $raw = ltrim($raw, '|!');
        // атрибути (съдържат `=`, без шаблони/връзки) преди разделителя `|`
        if (preg_match('/^([^|{\[]*=[^|]*)\|(.*)$/s', $raw, $m)) {
            return trim($m[2]);
        }

        return trim($raw);
    }

    /**
     * Парсва сезонна страница → списък със заглавия на кръгове (в календарен ред).
     *
     * Каноничният ред идва от „Report" линковете в round summary таблицата.
     * Свободният текст споменава кръгове извън ред (нови писти, бележки под
     * линия) и би разбъркал номерацията, ако просто сканираме всички линкове.
     *
     * @return array{rounds: array<int, string>}
     */
    public function parseSeasonPage(string $wikitext): array
    {
        preg_match_all('/\[\[(\d{4} .+? Formula 2 round)\|Report\]\]/', $wikitext, $m);
        $rounds = collect($m[1])->unique()->values()->all();

        // Фолбек за страници без summary таблица (напр. началото на сезона).
        if ($rounds === []) {
            preg_match_all('/\[\[(\d{4} .+? Formula 2 round)(?:\||\]\])/', $wikitext, $m);
            $rounds = collect($m[1])->unique()->values()->all();
        }

        return ['rounds' => $rounds];
    }

    // --- помощни ---

    private function extractSection(string $wikitext, string $heading): string
    {
        $pattern = '/={2,}\s*'.preg_quote($heading, '/').'\s*={2,}(.*?)(?=\n={2,}[^=]|\z)/s';

        return preg_match($pattern, $wikitext, $m) ? $m[1] : '';
    }

    private function firstTable(string $section): ?string
    {
        if (! preg_match('/\{\|.*?\n\|\}/s', $section, $m)) {
            return null;
        }

        return $m[0];
    }

    /**
     * @return array{driver:?string, time:?string}
     */
    private function fastestLap(string $section): array
    {
        if (! preg_match('/Fastest lap:\s*(.*?)(?:\{\{Ref|<ref|\n)/s', $section, $m)) {
            return ['driver' => null, 'time' => null];
        }

        $chunk = $m[1];
        $time = preg_match('/\\(?([0-9]:[0-9]{2}\\.[0-9]{3})/', $chunk, $t) ? $t[1] : null;

        return ['driver' => $this->firstLink($chunk), 'time' => $time];
    }

    /**
     * Стойност на поле от инфобокса. Поддържа двата формата на Wikipedia:
     *  - многоредов: `| Field = value` (всяко поле на свой ред);
     *  - едноредов:  `{{Infobox|Field=value|Next=...}}` (полета, разделени с `|`).
     * При едноредовия стойността спира при следващото поле (`|име=`) или края
     * на шаблона (`}}`), но НЕ при `|` вътре в wiki-връзка (`[[A|B]]`).
     */
    private function infoboxField(string $wikitext, string $field): ?string
    {
        $q = preg_quote($field, '/');

        // Многоредов формат — стойност до края на реда.
        if (preg_match('/^\s*\|\s*'.$q.'\s*=\s*(.*)$/m', $wikitext, $m)) {
            $value = trim($m[1]);
            if ($value !== '') {
                return $value;
            }
        }

        // Едноредов/inline формат.
        if (preg_match('/\|\s*'.$q.'\s*=\s*(.*?)\s*(?=\|\s*[A-Za-z_][\w-]*\s*=|\}\})/s', $wikitext, $m)) {
            $value = trim($m[1]);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Дисплей-текстът на първата wiki-връзка: `[[target|label]] → label`, `[[plain]] → plain`.
     */
    private function firstLink(string $text): ?string
    {
        return preg_match('/\[\[(?:[^\]|]*\|)?([^\]|]+)/', $text, $m) ? trim($m[1]) : null;
    }

    /**
     * Човешко име (пилот/отбор) от клетка — дисплей-текст без флагове/шаблони.
     */
    private function displayName(string $cell): ?string
    {
        return trim($this->stripWiki($cell)) ?: null;
    }

    /**
     * Сумира точки, вкл. адитивни нотации като `15+1` (бонус за най-бърза обиколка) → 16.0.
     */
    private function parsePoints(string $clean): float
    {
        $clean = str_replace(',', '.', trim($clean));

        if ($clean === '') {
            return 0.0;
        }

        return array_sum(array_map('floatval', preg_split('/\s*\+\s*/', $clean)));
    }

    /**
     * Стойност на pole полето — Wikipedia ползва суфикс по състезание (`Pole_driver_r2`).
     */
    private function poleField(string $wikitext): ?string
    {
        foreach (['Pole_driver_r2', 'Pole_driver_r1', 'Pole_driver'] as $field) {
            $value = $this->infoboxField($wikitext, $field);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function flag(string $text): ?string
    {
        return preg_match('/\{\{[Ff]lag\s?icon\|([A-Za-z]{2,3})/', $text, $m) ? strtoupper($m[1]) : null;
    }

    private function stripWiki(string $text): string
    {
        $text = preg_replace('/\{\{[^{}]*\}\}/', '', $text) ?? $text; // шаблони
        $text = preg_replace('/\[\[(?:[^\]|]*\|)?([^\]]*)\]\]/', '$1', $text) ?? $text; // връзки → дисплей
        $text = str_replace(["'''", "''"], '', $text); // bold/italic
        $text = preg_replace('/<ref.*?(?:\/>|<\/ref>)/s', '', $text) ?? $text;

        return trim($text);
    }

    private function intOrNull(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $value);

        return ($digits === '' || $digits === null) ? null : (int) $digits;
    }

    private function isPositionToken(string $token): bool
    {
        return ctype_digit($token) || in_array(strtoupper($token), self::STATUS_TOKENS, true);
    }
}
