<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Teams\ChampionshipBackfiller;
use Illuminate\Console\Command;

class BackfillConstructorChampionshipsCommand extends Command
{
    protected $signature = 'constructors:backfill-championships {--force : Презаписва и ръчно въведените стойности}';

    protected $description = 'Записва конструкторските титли от config/team-championships.php върху каноничните отбори.';

    public function handle(ChampionshipBackfiller $backfiller): int
    {
        $result = $backfiller->apply(force: (bool) $this->option('force'));

        if ($result['applied'] !== []) {
            $this->table(
                ['Отбор', 'Титли'],
                collect($result['applied'])->map(fn (int $count, string $slug) => [$slug, $count])->values()->all(),
            );
        }

        foreach ($result['skipped'] as $slug => $current) {
            $this->line("  {$slug}: запазена ръчната стойност ({$current}) — ползвай --force за презапис");
        }

        // Тихият провал тук е най-опасният: ако източникът промени изписването
        // на отбора, slug-ът не съвпада и страницата пак показва 0 титли.
        if ($result['missing'] !== []) {
            $this->warn('Няма каноничен отбор за: '.implode(', ', $result['missing']));
            $this->warn('Провери изписването в базата (constructors_canonical.slug) и обнови config/team-championships.php.');
        }

        $this->info(count($result['applied']).' отбора обновени.');

        return self::SUCCESS;
    }
}
