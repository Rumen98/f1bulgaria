<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Маппва демонимите от Ergast/Jolpica (напр. "Dutch") към ISO 3166-1 alpha-3
 * кодове (напр. "NLD"). Покрива националностите, срещани във F1.
 */
final class Nationality
{
    /** @var array<string, string> */
    private const MAP = [
        'British' => 'GBR',
        'German' => 'DEU',
        'Dutch' => 'NLD',
        'Spanish' => 'ESP',
        'Finnish' => 'FIN',
        'French' => 'FRA',
        'Mexican' => 'MEX',
        'Australian' => 'AUS',
        'Canadian' => 'CAN',
        'Monegasque' => 'MCO',
        'Italian' => 'ITA',
        'Japanese' => 'JPN',
        'Thai' => 'THA',
        'Danish' => 'DNK',
        'American' => 'USA',
        'Brazilian' => 'BRA',
        'Chinese' => 'CHN',
        'Austrian' => 'AUT',
        'Belgian' => 'BEL',
        'Swiss' => 'CHE',
        'Argentine' => 'ARG',
        'Argentinian' => 'ARG',
        'Russian' => 'RUS',
        'Polish' => 'POL',
        'Swedish' => 'SWE',
        'New Zealander' => 'NZL',
        'Portuguese' => 'PRT',
        'Indian' => 'IND',
        'Indonesian' => 'IDN',
        'South African' => 'ZAF',
        'Venezuelan' => 'VEN',
        'Colombian' => 'COL',
        'Hungarian' => 'HUN',
        'Czech' => 'CZE',
        'Irish' => 'IRL',
        'Malaysian' => 'MYS',
    ];

    public static function toIso3(?string $nationality): ?string
    {
        if ($nationality === null) {
            return null;
        }

        return self::MAP[trim($nationality)] ?? null;
    }
}
