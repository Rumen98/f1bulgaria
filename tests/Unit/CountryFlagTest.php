<?php

declare(strict_types=1);

use App\Support\CountryFlag;

it('маппва ISO3 кодове към alpha-2 за flag-icons', function () {
    expect(CountryFlag::iso2('NLD'))->toBe('nl')
        ->and(CountryFlag::iso2('GBR'))->toBe('gb')
        ->and(CountryFlag::iso2('BGR'))->toBe('bg');
});

it('маппва IOC кодовете от Wikipedia (F2)', function () {
    expect(CountryFlag::iso2('BUL'))->toBe('bg')
        ->and(CountryFlag::iso2('GER'))->toBe('de')
        ->and(CountryFlag::iso2('NED'))->toBe('nl');
});

it('връща null за непознат или липсващ код', function () {
    expect(CountryFlag::iso2('XYZ'))->toBeNull()
        ->and(CountryFlag::iso2(null))->toBeNull()
        ->and(CountryFlag::iso2(''))->toBeNull();
});

it('нормализира регистър и интервали', function () {
    expect(CountryFlag::iso2(' nld '))->toBe('nl');
});
