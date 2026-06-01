<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Predictions\PredictionLockService;
use Illuminate\Console\Command;

class LockPredictionsCommand extends Command
{
    protected $signature = 'f1:lock-predictions';

    protected $description = 'Заключва прогнозите 5 минути преди началото на квалификацията (върви всяка минута).';

    public function handle(PredictionLockService $service): int
    {
        $locked = $service->lockDue();

        if ($locked > 0) {
            $this->info("Заключени прогнози: {$locked}");
        }

        return self::SUCCESS;
    }
}
