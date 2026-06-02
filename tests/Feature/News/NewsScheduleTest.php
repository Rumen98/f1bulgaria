<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

function scheduledEvent(string $command): ?Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $e) => str_contains((string) $e->command, $command));
}

it('разписва news:fetch ежедневно в 06:00 без застъпване', function () {
    $event = scheduledEvent('news:fetch');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 6 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('разписва news:enrich ежедневно, след news:fetch', function () {
    $fetch = scheduledEvent('news:fetch');
    $enrich = scheduledEvent('news:enrich');

    expect($enrich)->not->toBeNull()
        ->and($enrich->withoutOverlapping)->toBeTrue()
        // и двете в 06:xx, но enrich е на 30-ата минута → след fetch (00-ата).
        ->and($fetch->expression)->toBe('0 6 * * *')
        ->and($enrich->expression)->toBe('30 6 * * *');
});

it('пуска news командите в background с лог файл', function () {
    $fetch = scheduledEvent('news:fetch');

    expect($fetch->runInBackground)->toBeTrue()
        ->and($fetch->output)->toContain('scheduler.log');
});
