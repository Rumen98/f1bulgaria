<?php

declare(strict_types=1);

namespace App\Filament\Resources\F2Drivers\Tables;

use App\Models\F2Driver;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class F2DriversTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('position')->label('Поз.')->sortable(),
                TextColumn::make('last_name')->label('Пилот')
                    ->formatStateUsing(fn (F2Driver $r) => $r->fullName())
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('team.name')->label('Отбор'),
                TextColumn::make('season.year')->label('Сезон')->sortable(),
                TextColumn::make('points')->label('Точки')->sortable(),
                IconColumn::make('is_champion')->label('Шампион')->boolean(),
            ])
            ->defaultSort('season.year', 'desc')
            ->filters([
                SelectFilter::make('season')->relationship('season', 'year'),
                TernaryFilter::make('is_champion')->label('Шампион'),
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
