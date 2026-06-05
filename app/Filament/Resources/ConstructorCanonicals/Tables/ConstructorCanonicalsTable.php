<?php

declare(strict_types=1);

namespace App\Filament\Resources\ConstructorCanonicals\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ConstructorCanonicalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Отбор')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('total_wins')->label('Победи')->sortable(),
                TextColumn::make('total_poles')->label('Pole')->sortable(),
                TextColumn::make('total_races')->label('Старта')->sortable(),
                IconColumn::make('is_active')->label('Активен')->boolean(),
            ])
            ->defaultSort('total_wins', 'desc')
            ->filters([
                TernaryFilter::make('is_active')->label('Активен отбор'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
