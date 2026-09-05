<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TeamNewsItem;
use App\Services\News\TsolovDetector;
use Illuminate\Console\Command;

/**
 * Маркира съществуващите новини за Никола Цолов.
 *
 * Разпознаването при вземането важи само за новите статии. Архивът е влязъл
 * преди колонката да съществува, тоест кътът му стартира празен, докато в
 * базата вече има негови статии. Тази команда наваксва назад.
 *
 * Идемпотентна и преизпълнима — пуска се пак и когато се разшири списъкът с
 * форми на името.
 */
class FlagTsolovNewsCommand extends Command
{
    protected $signature = 'news:flag-tsolov {--dry-run : Показва какво би маркирало, без да пипа базата}';

    protected $description = 'Маркира вече вписаните новини, чието заглавие споменава Никола Цолов.';

    public function handle(TsolovDetector $detector): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $flagged = 0;

        TeamNewsItem::query()
            ->where('is_tsolov', false)
            ->chunkById(200, function ($items) use ($detector, $dryRun, &$flagged): void {
                foreach ($items as $item) {
                    // И двете заглавия: източникът е на английски, но ръчно
                    // въведените и вече преведените носят името на кирилица.
                    if (! $detector->matchesTitle($item->title_original)
                        && ! $detector->matchesTitle($item->title_bg)) {
                        continue;
                    }

                    $this->line("#{$item->id} ".($item->title_bg ?? $item->title_original));

                    if (! $dryRun) {
                        $item->update(['is_tsolov' => true]);
                    }

                    $flagged++;
                }
            });

        $this->info($dryRun
            ? "Биха били маркирани: {$flagged}"
            : "Маркирани новини за Цолов: {$flagged}");

        return self::SUCCESS;
    }
}
