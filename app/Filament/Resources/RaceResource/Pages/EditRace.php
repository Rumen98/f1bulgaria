<?php

declare(strict_types=1);

namespace App\Filament\Resources\RaceResource\Pages;

use App\Filament\Resources\RaceResource;
use App\Services\Predictions\PredictionScoringService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRace extends EditRecord
{
    protected static string $resource = RaceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Ръчна корекция (safety car, pole...) → преизчисли точките на прогнозите.
     */
    protected function afterSave(): void
    {
        app(PredictionScoringService::class)->scoreRace($this->record->fresh());
    }
}
