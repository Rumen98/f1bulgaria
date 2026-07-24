<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

function f2ScheduledEvent(string $command): ?Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $e) => str_contains((string) $e->command, $command));
}

it('разписва f2:sync-wikipedia дневно в 06:45, преди sitemap-а', function () {
    $sync = f2ScheduledEvent('f2:sync-wikipedia');
    $sitemap = f2ScheduledEvent('sitemap:generate');

    expect($sync)->not->toBeNull()
        ->and($sync->expression)->toBe('45 6 * * *')
        ->and($sync->withoutOverlapping)->toBeTrue()
        ->and($sync->runInBackground)->toBeTrue()
        ->and($sync->output)->toContain('scheduler.log')
        // Sitemap-ът включва F2 слъговете → върви след синхрона.
        ->and($sitemap->expression)->toBe('0 7 * * *');
});
