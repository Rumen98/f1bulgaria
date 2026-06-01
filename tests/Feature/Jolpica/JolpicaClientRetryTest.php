<?php

declare(strict_types=1);

use App\Services\Jolpica\JolpicaClient;
use App\Services\Jolpica\JolpicaException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Без реални паузи в тестовете.
    config()->set('services.jolpica.retry_times', 3);
    config()->set('services.jolpica.retry_sleep_ms', 0);
});

it('повтаря при 504 и успява при следващ 200', function () {
    Http::fake([
        '*' => Http::sequence()
            ->push('', 504)
            ->push(['MRData' => ['total' => '0', 'RaceTable' => ['Races' => []]]], 200),
    ]);

    $data = app(JolpicaClient::class)->get('2024/21/results');

    expect($data)->toHaveKey('RaceTable');
    Http::assertSentCount(2);
});

it('не повтаря при 4xx (наша грешка) и хвърля изключение веднага', function () {
    Http::fake(['*' => Http::response('Not Found', 404)]);

    expect(fn () => app(JolpicaClient::class)->get('2024/999/results'))
        ->toThrow(JolpicaException::class);

    Http::assertSentCount(1);
});

it('хвърля изключение след изчерпване на опитите при постоянен 504', function () {
    Http::fake(['*' => Http::response('', 504)]);

    expect(fn () => app(JolpicaClient::class)->get('2024/21/results'))
        ->toThrow(JolpicaException::class);

    // 3 опита (retry_times = 3).
    Http::assertSentCount(3);
});

it('повтаря при 429 (rate limit)', function () {
    Http::fake([
        '*' => Http::sequence()
            ->push('', 429)
            ->push(['MRData' => ['StandingsTable' => ['StandingsLists' => []]]], 200),
    ]);

    $data = app(JolpicaClient::class)->get('2024/driverstandings');

    expect($data)->toHaveKey('StandingsTable');
    Http::assertSentCount(2);
});
