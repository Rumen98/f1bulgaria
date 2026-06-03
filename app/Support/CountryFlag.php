<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Маппва ISO 3166-1 alpha-3 кодове (както ги пазим в drivers.country_code)
 * към emoji знаме. Покрива националностите, срещани във F1.
 */
final class CountryFlag
{
    /** @var array<string, string> */
    private const MAP = [
        'GBR' => '🇬🇧', 'DEU' => '🇩🇪', 'NLD' => '🇳🇱', 'ESP' => '🇪🇸', 'FIN' => '🇫🇮',
        'FRA' => '🇫🇷', 'MEX' => '🇲🇽', 'AUS' => '🇦🇺', 'CAN' => '🇨🇦', 'MCO' => '🇲🇨',
        'ITA' => '🇮🇹', 'JPN' => '🇯🇵', 'THA' => '🇹🇭', 'DNK' => '🇩🇰', 'USA' => '🇺🇸',
        'BRA' => '🇧🇷', 'CHN' => '🇨🇳', 'AUT' => '🇦🇹', 'BEL' => '🇧🇪', 'CHE' => '🇨🇭',
        'ARG' => '🇦🇷', 'RUS' => '🇷🇺', 'POL' => '🇵🇱', 'SWE' => '🇸🇪', 'NZL' => '🇳🇿',
        'PRT' => '🇵🇹', 'IND' => '🇮🇳', 'IDN' => '🇮🇩', 'ZAF' => '🇿🇦', 'COL' => '🇨🇴',
        'HUN' => '🇭🇺', 'CZE' => '🇨🇿', 'IRL' => '🇮🇪', 'MYS' => '🇲🇾', 'VEN' => '🇻🇪',
    ];

    public static function emoji(?string $iso3): string
    {
        if ($iso3 === null) {
            return '';
        }

        return self::MAP[strtoupper($iso3)] ?? '';
    }
}
