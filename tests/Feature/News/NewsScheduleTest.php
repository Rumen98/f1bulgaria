<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

function scheduledEvent(string $command): ?Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $e) => str_contains((string) $e->command, $command));
}

it('разписва news:fetch на всеки 30 минути без застъпване', function () {
    $event = scheduledEvent('news:fetch');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0,30 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('разписва news:enrich на всеки 30 минути, отместен след news:fetch', function () {
    $enrich = scheduledEvent('news:enrich');

    expect($enrich)->not->toBeNull()
        ->and($enrich->withoutOverlapping)->toBeTrue()
        // fetch е на :00/:30, enrich на :05/:35 → винаги след прясното вземане.
        ->and($enrich->expression)->toBe('5,35 * * * *');
});

it('пуска news командите в background с лог файл', function () {
    $fetch = scheduledEvent('news:fetch');

    expect($fetch->runInBackground)->toBeTrue()
        ->and($fetch->output)->toContain('scheduler.log');
});

it('разписва news:publish-pending ежечасно като осигурителна мрежа', function () {
    $publish = scheduledEvent('news:publish-pending');

    expect($publish)->not->toBeNull()
        ->and($publish->expression)->toBe('20 * * * *')
        ->and($publish->withoutOverlapping)->toBeTrue();
});

it('разписва news:generate-articles ежечасно', function () {
    $generate = scheduledEvent('news:generate-articles');

    expect($generate)->not->toBeNull()
        ->and($generate->expression)->toBe('25 * * * *')
        ->and((string) $generate->command)->toContain('--limit=10')
        ->and($generate->withoutOverlapping)->toBeTrue();
});

it('разписва news:normalize-bg между обогатяването и канала', function () {
    $normalize = scheduledEvent('news:normalize-bg');

    // enrich е на :05/:35 (~8 мин на партида), channel:enqueue-news на
    // :23/:53 — поправката минава между двете, за да не тръгне сгрешено
    // име към Telegram.
    expect($normalize)->not->toBeNull()
        ->and($normalize->expression)->toBe('15,45 * * * *')
        ->and($normalize->withoutOverlapping)->toBeTrue();
});

it('разписва news:health-check ежечасно извън news слотовете', function () {
    $health = scheduledEvent('news:health-check');

    // :50 не се засича с :00/:30 fetch, :05/:35 enrich, :15/:45 normalize,
    // :20 publish-pending, :25 generate-articles.
    expect($health)->not->toBeNull()
        ->and($health->expression)->toBe('50 * * * *')
        ->and($health->withoutOverlapping)->toBeTrue();
});
