<?php

declare(strict_types=1);

namespace App\Filament\Resources\F2Teams\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class F2TeamsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Отбор')->searchable()->sortable(),
                TextColumn::make('season.year')->label('Сезон')->sortable(),
                TextColumn::make('drivers_count')->label('Пилоти')->counts('drivers'),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('season')->relationship('season', 'year'),
            ])
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
