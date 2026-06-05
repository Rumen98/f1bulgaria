<?php

declare(strict_types=1);

namespace App\Filament\Resources\ConstructorCanonicals\Pages;

use App\Filament\Resources\ConstructorCanonicals\ConstructorCanonicalResource;
use Filament\Resources\Pages\ListRecords;

class ListConstructorCanonicals extends ListRecords
{
    protected static string $resource = ConstructorCanonicalResource::class;
}
