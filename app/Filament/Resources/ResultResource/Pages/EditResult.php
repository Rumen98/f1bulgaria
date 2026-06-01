<?php

declare(strict_types=1);

namespace App\Filament\Resources\ResultResource\Pages;

use App\Filament\Resources\ResultResource;
use App\Services\Predictions\PredictionScoringService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditResult extends EditRecord
{
    protected static string $resource = ResultResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Ръчна корекция на резултат → преизчисли точките на прогнозите за това състезание.
     */
    protected function afterSave(): void
    {
        $race = $this->record->race;

        if ($race !== null) {
            app(PredictionScoringService::class)->scoreRace($race);
        }
    }
}
