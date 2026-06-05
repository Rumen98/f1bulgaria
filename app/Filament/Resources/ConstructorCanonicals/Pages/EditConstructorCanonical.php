<?php

declare(strict_types=1);

namespace App\Filament\Resources\ConstructorCanonicals\Pages;

use App\Filament\Resources\ConstructorCanonicals\ConstructorCanonicalResource;
use Filament\Resources\Pages\EditRecord;

class EditConstructorCanonical extends EditRecord
{
    protected static string $resource = ConstructorCanonicalResource::class;
}
