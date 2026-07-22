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
        'NOR' => '🇳🇴', 'EST' => '🇪🇪', 'PRY' => '🇵🇾',

        // IOC кодове (както ги дава Wikipedia за F2) — допълват ISO3 по-горе.
        'BUL' => '🇧🇬', 'GER' => '🇩🇪', 'NED' => '🇳🇱', 'SUI' => '🇨🇭', 'PAR' => '🇵🇾',
        'IRE' => '🇮🇪', 'DEN' => '🇩🇰', 'RSA' => '🇿🇦', 'INA' => '🇮🇩', 'POR' => '🇵🇹',
        'MON' => '🇲🇨', 'CHI' => '🇨🇱', 'URU' => '🇺🇾', 'NZL' => '🇳🇿', 'JPN' => '🇯🇵',
    ];

    public static function emoji(?string $iso3): string
    {
        // Emoji знамената са изключени — на Windows не се рендерират като флагове,
        // а като двубуквен код/кутийки пред имената. За да ги върнеш, ползвай:
        // return $iso3 === null ? '' : (self::MAP[strtoupper($iso3)] ?? '');
        return '';
    }
}
