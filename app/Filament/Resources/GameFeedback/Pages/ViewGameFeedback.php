<?php

namespace App\Filament\Resources\GameFeedback\Pages;

use App\Filament\Resources\GameFeedback\GameFeedbackResource;
use Filament\Resources\Pages\ViewRecord;

class ViewGameFeedback extends ViewRecord
{
    protected static string $resource = GameFeedbackResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }
}
