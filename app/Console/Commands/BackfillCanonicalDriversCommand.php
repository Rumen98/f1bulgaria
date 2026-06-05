<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Drivers\CanonicalDriverBackfiller;
use Illuminate\Console\Command;

class BackfillCanonicalDriversCommand extends Command
{
    protected $signature = 'drivers:backfill-canonical';

    protected $description = 'Изгражда каноничните пилоти (1 запис на човек) и свързва per-season записите.';

    public function handle(CanonicalDriverBackfiller $backfiller): int
    {
        $stats = $backfiller->backfill();

        $this->table(
            ['Канонични пилоти', 'Свързани per-season записи'],
            [[$stats['canonical'], $stats['linked']]],
        );

        $this->info('Готово.');

        return self::SUCCESS;
    }
}
