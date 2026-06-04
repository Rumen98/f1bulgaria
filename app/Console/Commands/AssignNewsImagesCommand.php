<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TeamNewsItem;
use App\Services\News\NewsImageResolver;
use Illuminate\Console\Command;

class AssignNewsImagesCommand extends Command
{
    protected $signature = 'news:assign-images {--limit=500 : Максимален брой новини} {--force : Преизчисли и за вече назначените}';

    protected $description = 'Назначава featured_image (визуален header) на новините чрез NewsImageResolver.';

    public function handle(NewsImageResolver $resolver): int
    {
        $items = TeamNewsItem::query()
            ->with('constructor')
            ->when(! $this->option('force'), fn ($q) => $q->whereNull('featured_image'))
            ->limit((int) $this->option('limit'))
            ->get();

        $byType = [];

        foreach ($items as $item) {
            $meta = $resolver->resolve($item);
            $item->update(['featured_image' => $meta]);
            $byType[$meta['type']] = ($byType[$meta['type']] ?? 0) + 1;
        }

        $this->info("Назначени изображения: {$items->count()}");

        foreach ($byType as $type => $count) {
            $this->line("  {$type}: {$count}");
        }

        return self::SUCCESS;
    }
}
