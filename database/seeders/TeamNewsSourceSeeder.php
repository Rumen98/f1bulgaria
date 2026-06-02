<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\TeamNewsSource;
use Illuminate\Database\Seeder;

class TeamNewsSourceSeeder extends Seeder
{
    /**
     * Глобални RSS източници (constructor_id = null) — важат за всички отбори.
     *
     * @var array<int, array{name:string, feed_url:string}>
     */
    private const GLOBAL_SOURCES = [
        ['name' => 'Autosport', 'feed_url' => 'https://www.autosport.com/rss/feed/f1'],
        ['name' => 'The Race', 'feed_url' => 'https://the-race.com/feed/'],
        ['name' => 'Motorsport.com F1', 'feed_url' => 'https://www.motorsport.com/rss/f1/news/'],
        ['name' => 'RacingNews365', 'feed_url' => 'https://racingnews365.com/feeds/news/f1'],
        ['name' => 'RaceFans', 'feed_url' => 'https://www.racefans.net/feed/'],
    ];

    public function run(): void
    {
        foreach (self::GLOBAL_SOURCES as $source) {
            TeamNewsSource::query()->updateOrCreate(
                ['feed_url' => $source['feed_url']],
                [
                    'constructor_id' => null,
                    'name' => $source['name'],
                    'type' => 'rss',
                    'language' => 'en',
                    'is_active' => true,
                ],
            );
        }
    }
}
