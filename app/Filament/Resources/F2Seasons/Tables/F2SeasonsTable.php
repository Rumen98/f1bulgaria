<?php

declare(strict_types=1);

namespace App\Filament\Resources\F2Seasons\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class F2SeasonsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('year')->label('Година')->sortable(),
                IconColumn::make('is_current')->label('Текущ')->boolean(),
                TextColumn::make('drivers_count')->label('Пилоти')->counts('drivers'),
                TextColumn::make('teams_count')->label('Отбори')->counts('teams'),
            ])
            ->defaultSort('year', 'desc')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
