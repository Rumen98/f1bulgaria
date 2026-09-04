<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * Точният брой над таблицата. Стои над самата таблица, значи се вижда и
     * на телефон, където обобщението за страницирането се свива.
     */
    public function getSubheading(): ?string
    {
        $total = User::count();
        $banned = User::whereNotNull('banned_at')->count();

        return $banned > 0
            ? "Общо {$total} потребители, от които {$banned} блокирани"
            : "Общо {$total} потребители";
    }
}
