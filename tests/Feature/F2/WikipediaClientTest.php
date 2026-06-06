<?php

declare(strict_types=1);

use App\Services\F2\WikipediaClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    config(['services.wikipedia.rate_limit_ms' => 0]); // без забавяне в тестове
});

function wikiClient(): WikipediaClient
{
    return app(WikipediaClient::class);
}

function fakeParse(string $wikitext): array
{
    return ['parse' => ['title' => 'X', 'wikitext' => $wikitext]];
}

it('взима wikitext на сезонна страница', function () {
    Http::fake(['*' => Http::response(fakeParse('== Race calendar ==\n[[2026 Melbourne Formula 2 round]]'))]);

    $w = wikiClient()->getSeasonPage(2026);

    expect($w)->toContain('Race calendar');
    Http::assertSent(fn ($req) => str_contains($req->url(), 'Formula+2+Championship') || str_contains(urldecode($req->url()), 'Formula 2 Championship'));
});

it('взима wikitext на кръг', function () {
    Http::fake(['*' => Http::response(fakeParse('=== Sprint race ===\n{| class="wikitable"'))]);

    expect(wikiClient()->getRoundPage(2026, 'Melbourne'))->toContain('Sprint race');
});

it('праща правилен User-Agent', function () {
    Http::fake(['*' => Http::response(fakeParse('x'))]);

    wikiClient()->getPage('Test');

    Http::assertSent(fn ($req) => str_contains($req->header('User-Agent')[0] ?? '', 'F1Bulgaria'));
});

it('кешира — втора заявка не удря API', function () {
    Http::fake(['*' => Http::response(fakeParse('cached content'))]);

    wikiClient()->getPage('Same Title');
    wikiClient()->getPage('Same Title');

    Http::assertSentCount(1);
});

it('връща null при липсваща статия', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 'missingtitle', 'info' => 'no']])]);

    expect(wikiClient()->getPage('Nonexistent Article'))->toBeNull();
});

it('кешира и липсващите статии — не удря API повторно (#6)', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 'missingtitle', 'info' => 'no']])]);

    expect(wikiClient()->getPage('Future Round'))->toBeNull()
        ->and(wikiClient()->getPage('Future Round'))->toBeNull();

    Http::assertSentCount(1); // вторият път идва от negative cache
});

it('връща null при disambiguation', function () {
    Http::fake(['*' => Http::response(fakeParse('{{disambiguation}}\nMultiple meanings'))]);

    expect(wikiClient()->getPage('Ambiguous'))->toBeNull();
});

it('връща null при HTTP грешка (graceful)', function () {
    Http::fake(['*' => Http::response('error', 500)]);

    expect(wikiClient()->getPage('Server Error'))->toBeNull();
});

it('връща null при изключение (timeout)', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    expect(wikiClient()->getPage('Timeout'))->toBeNull();
});
