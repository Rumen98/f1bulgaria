<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Drivers\DriverCodeGenerator;
use Illuminate\Console\Command;

class AssignHistoricalDriverCodesCommand extends Command
{
    protected $signature = 'drivers:assign-historical-codes {--show-samples : Изброй генерираните кодове}';

    protected $description = 'Генерира driver_code за историческите пилоти (pre-2006), за да участват в all-time класиранията.';

    public function handle(DriverCodeGenerator $generator): int
    {
        $stats = $generator->assignAll();

        if ($this->option('show-samples')) {
            foreach ($stats['samples'] as $name => $code) {
                $this->line("  {$name} → {$code}");
            }
        }

        $this->table(
            ['Обновени редове', 'Нови кодове', 'Преизползвани', 'Колизии'],
            [[$stats['updated'], $stats['generated'], $stats['reused'], $stats['collisions']]],
        );

        $this->info($stats['updated'] === 0 ? 'Няма пилоти без код — нищо за правене.' : 'Готово.');

        return self::SUCCESS;
    }
}
