<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Маппва ISO 3166-1 alpha-3 кодове (drivers.country_code) и IOC кодове
 * (Wikipedia/F2) към ISO alpha-2 за SVG знамената на flag-icons
 * (класове `fi fi-{alpha2}` във FlagIcon.vue). Emoji знамената са
 * изоставени нарочно — Windows ги рендерира като букви.
 */
final class CountryFlag
{
    /** @var array<string, string> */
    private const MAP = [
        // ISO 3166-1 alpha-3
        'GBR' => 'gb', 'DEU' => 'de', 'NLD' => 'nl', 'ESP' => 'es', 'FIN' => 'fi',
        'FRA' => 'fr', 'MEX' => 'mx', 'AUS' => 'au', 'CAN' => 'ca', 'MCO' => 'mc',
        'ITA' => 'it', 'JPN' => 'jp', 'THA' => 'th', 'DNK' => 'dk', 'USA' => 'us',
        'BRA' => 'br', 'CHN' => 'cn', 'AUT' => 'at', 'BEL' => 'be', 'CHE' => 'ch',
        'ARG' => 'ar', 'RUS' => 'ru', 'POL' => 'pl', 'SWE' => 'se', 'NZL' => 'nz',
        'PRT' => 'pt', 'IND' => 'in', 'IDN' => 'id', 'ZAF' => 'za', 'COL' => 'co',
        'HUN' => 'hu', 'CZE' => 'cz', 'IRL' => 'ie', 'MYS' => 'my', 'VEN' => 've',
        'NOR' => 'no', 'EST' => 'ee', 'PRY' => 'py', 'BGR' => 'bg', 'CHL' => 'cl',
        'URY' => 'uy', 'PER' => 'pe', 'MAR' => 'ma', 'LIE' => 'li', 'LUX' => 'lu',
        'GRC' => 'gr', 'TUR' => 'tr', 'UKR' => 'ua', 'ROU' => 'ro', 'HRV' => 'hr',
        'SRB' => 'rs', 'SVK' => 'sk', 'SVN' => 'si', 'ISR' => 'il', 'EGY' => 'eg',

        // IOC кодове (Wikipedia за F2), където се разминават с ISO3.
        'BUL' => 'bg', 'GER' => 'de', 'NED' => 'nl', 'SUI' => 'ch', 'PAR' => 'py',
        'IRE' => 'ie', 'DEN' => 'dk', 'RSA' => 'za', 'INA' => 'id', 'POR' => 'pt',
        'MON' => 'mc', 'CHI' => 'cl', 'URU' => 'uy', 'GRE' => 'gr', 'CRO' => 'hr',
    ];

    /**
     * ISO alpha-2 код (lowercase) за flag-icons, или null при непознат код.
     */
    public static function iso2(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return self::MAP[strtoupper(trim($code))] ?? null;
    }
}
