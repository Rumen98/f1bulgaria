<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TeamNewsItem;
use Illuminate\Console\Command;

/**
 * Поправя известни грешни транслитерации в българския текст на новините
 * (генериран от LLM). Идемпотентно и преизпълнимо след всеки news:fetch.
 */
class NormalizeNewsBulgarianCommand extends Command
{
    protected $signature = 'news:normalize-bg';

    protected $description = 'Поправя грешни български транслитерации в новините (напр. Алпайн → Алпин).';

    /**
     * Карта на корекциите (грешно → правилно). Разширявай при нужда.
     *
     * @var array<string, string>
     */
    private const REPLACEMENTS = [
        'Алпайн' => 'Алпин',
        'Ферарри' => 'Ферари',
        'Макларен' => 'Макларън',
    ];

    public function handle(): int
    {
        $fixed = 0;

        TeamNewsItem::query()
            ->where(function ($q) {
                foreach (array_keys(self::REPLACEMENTS) as $bad) {
                    $q->orWhere('title_bg', 'like', "%{$bad}%")->orWhere('summary_bg', 'like', "%{$bad}%");
                }
            })
            ->chunkById(100, function ($items) use (&$fixed) {
                foreach ($items as $item) {
                    $title = $this->normalize($item->title_bg);
                    $summary = $this->normalize($item->summary_bg);

                    if ($title !== $item->title_bg || $summary !== $item->summary_bg) {
                        $item->update(['title_bg' => $title, 'summary_bg' => $summary]);
                        $fixed++;
                    }
                }
            });

        $this->info("Поправени новини: {$fixed}");

        return self::SUCCESS;
    }

    private function normalize(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        return str_replace(array_keys(self::REPLACEMENTS), array_values(self::REPLACEMENTS), $text);
    }
}
