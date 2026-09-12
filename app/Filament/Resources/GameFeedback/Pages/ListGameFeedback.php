<?php

namespace App\Filament\Resources\GameFeedback\Pages;

use App\Filament\Resources\GameFeedback\GameFeedbackResource;
use Filament\Resources\Pages\ListRecords;

class ListGameFeedback extends ListRecords
{
    protected static string $resource = GameFeedbackResource::class;

    public function getSubheading(): ?string
    {
        return 'Мнения само от хора, които са стартирали играта или имат записана обиколка. Оценките са от 1 до 5. Празен отговор не е отрицателна оценка; общите мнения нямат устройство или писта.';
    }
}
