<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

function f2ScheduledEvent(string $command): ?Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $e) => str_contains((string) $e->command, $command));
}

it('разписва f2:sync на 15 минути и sitemap ежечасно', function () {
    // Търсим точно f2:sync, а не f2:sync-wikipedia — str_contains би хванал и двете.
    $sync = collect(app(Schedule::class)->events())
        ->first(fn (Event $e) => preg_match('/f2:sync(?!-)/', (string) $e->command) === 1);

    $sitemap = f2ScheduledEvent('sitemap:generate');

    expect($sync)->not->toBeNull()
        ->and($sync->expression)->toBe('*/15 * * * *')
        ->and($sync->withoutOverlapping)->toBeTrue()
        ->and($sync->runInBackground)->toBeTrue()
        ->and($sync->output)->toContain('scheduler.log')
        // Sitemap-ът е ежечасен (новините излизат през целия ден) — F2
        // слъговете от синхрона влизат при следващото завъртане.
        ->and($sitemap->expression)->toBe('45 * * * *');
});

it('НЕ разписва синхрона от Wikipedia', function () {
    // Основният източник е официалният API. Пуснат по разписание върху
    // текущия сезон, Wikipedia синхронът би презаписал пресните данни с
    // по-бедни и по-стари — затова остава само ръчен.
    expect(f2ScheduledEvent('f2:sync-wikipedia'))->toBeNull();
});

it('разписва изпразването на опашката към канала на 5 минути', function () {
    $post = f2ScheduledEvent('channel:post');

    expect($post)->not->toBeNull()
        ->and($post->expression)->toBe('*/5 * * * *')
        // Без това две едновременни пускания биха публикували един и същи ред.
        ->and($post->withoutOverlapping)->toBeTrue();
});
