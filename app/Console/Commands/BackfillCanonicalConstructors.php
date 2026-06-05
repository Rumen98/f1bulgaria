<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Teams\CanonicalConstructorBackfiller;
use Illuminate\Console\Command;

class BackfillCanonicalConstructors extends Command
{
    protected $signature = 'constructors:backfill-canonical';

    protected $description = 'Изгражда каноничните конструктори (1 запис на отбор) и свързва per-season записите.';

    public function handle(CanonicalConstructorBackfiller $backfiller): int
    {
        $stats = $backfiller->backfill();

        $this->table(
            ['Канонични конструктори', 'Свързани per-season записи'],
            [[$stats['canonical'], $stats['linked']]],
        );

        $this->info('Готово.');

        return self::SUCCESS;
    }
}
