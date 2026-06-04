<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Drivers\DriverCodeCollisionFixer;
use Illuminate\Console\Command;

class FixDriverCodeCollisionsCommand extends Command
{
    protected $signature = 'drivers:fix-code-collisions';

    protected $description = 'Поправя колизии в driver_code (различни хора с един код) — задържа кода за пилота с най-много състезания.';

    public function handle(DriverCodeCollisionFixer $fixer): int
    {
        $stats = $fixer->fix();

        foreach ($stats['reassignments'] as $line) {
            $this->line("  {$line}");
        }

        $this->table(
            ['Открити колизии', 'Преразпределени реда'],
            [[$stats['collisions'], $stats['reassigned']]],
        );

        $this->info($stats['collisions'] === 0 ? 'Няма колизии — нищо за поправяне.' : 'Готово.');

        return self::SUCCESS;
    }
}
