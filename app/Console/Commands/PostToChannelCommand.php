<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ChannelPostStatus;
use App\Models\ChannelPost;
use App\Services\Telegram\ChannelPublisher;
use Illuminate\Console\Command;

class PostToChannelCommand extends Command
{
    protected $signature = 'channel:post
        {--limit= : Максимален брой публикации (по подразбиране channel.batch_limit)}
        {--retry-failed : Връща провалените публикации в опашката и нулира опитите}
        {--dry-run : Показва какво би тръгнало, без да праща}';

    protected $description = 'Изпраща чакащите публикации от опашката към Telegram канала.';

    public function handle(ChannelPublisher $publisher): int
    {
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('retry-failed')) {
            $this->retryFailed($dryRun);
        }

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

    /**
     * Провалените не се съживяват сами — причината обикновено е постоянна.
     * Затова връщането им в опашката е изрично действие, не автоматично.
     */
    private function retryFailed(bool $dryRun): void
    {
        $query = ChannelPost::query()->where('status', ChannelPostStatus::Failed->value);
        $count = $query->count();

        if ($count === 0) {
            $this->line('Няма провалени публикации за връщане.');

            return;
        }

        if ($dryRun) {
            $this->line("Пробно пускане: {$count} провалени биха се върнали в опашката.");

            return;
        }

        // Опитите се нулират — иначе таванът от предишния провал ги спира веднага.
        $query->update([
            'status' => ChannelPostStatus::Pending->value,
            'attempts' => 0,
            'last_error' => null,
        ]);

        $this->line("Върнати в опашката: {$count}");
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
