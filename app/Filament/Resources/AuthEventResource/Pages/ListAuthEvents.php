<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuthEventResource\Pages;

use App\Filament\Resources\AuthEventResource;
use Filament\Resources\Pages\ListRecords;

class ListAuthEvents extends ListRecords
{
    protected static string $resource = AuthEventResource::class;
}
