<?php

declare(strict_types=1);

namespace App\Filament\Resources\Rivalries\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RivalriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title_bg')->label('Съперничество')->searchable()->sortable(),
                TextColumn::make('driverOne.last_name')->label('Пилот 1'),
                TextColumn::make('driverTwo.last_name')->label('Пилот 2'),
                TextColumn::make('era_start_year')->label('Ера')->formatStateUsing(
                    fn ($state, $record) => $record->era_end_year ? "{$state}–{$record->era_end_year}" : $state,
                ),
                IconColumn::make('is_featured')->label('Топ')->boolean(),
            ])
            ->defaultSort('is_featured', 'desc')
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
