<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ChannelPost;
use App\Services\Telegram\ChannelPublisher;
use Illuminate\Console\Command;

class PostToChannelCommand extends Command
{
    protected $signature = 'channel:post
        {--limit= : Максимален брой публикации (по подразбиране channel.batch_limit)}
        {--dry-run : Показва какво би тръгнало, без да праща}';

    protected $description = 'Изпраща чакащите публикации от опашката към Telegram канала.';

    public function handle(ChannelPublisher $publisher): int
    {
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->previewQueue($limit);
        }

        $stats = $publisher->publish($limit, $dryRun);

        if ($stats['processed'] === 0 && $stats['errors'] === []) {
            $this->info('Няма чакащи публикации.');

            return self::SUCCESS;
        }

        $this->table(
            ['Обработени', 'Изпратени', 'Провалени'],
            [[$stats['processed'], $stats['sent'], $stats['failed']]],
        );

        foreach ($stats['errors'] as $error) {
            $this->warn($error);
        }

        $this->info($dryRun ? 'Пробно пускане — нищо не е изпратено.' : 'Готово.');

        return self::SUCCESS;
    }

    private function previewQueue(?int $limit): void
    {
        $posts = ChannelPost::query()
            ->ready()
            ->limit($limit ?? (int) config('channel.batch_limit', 10))
            ->get();

        foreach ($posts as $post) {
            $this->line("#{$post->id} [{$post->kind->value}] {$post->kind->label()}");
            $this->line(strip_tags($post->body));
            $this->newLine();
        }
    }
}
