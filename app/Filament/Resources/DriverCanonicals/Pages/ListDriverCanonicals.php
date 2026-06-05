<?php

declare(strict_types=1);

namespace App\Filament\Resources\DriverCanonicals\Pages;

use App\Filament\Resources\DriverCanonicals\DriverCanonicalResource;
use Filament\Resources\Pages\ListRecords;

class ListDriverCanonicals extends ListRecords
{
    protected static string $resource = DriverCanonicalResource::class;
}
