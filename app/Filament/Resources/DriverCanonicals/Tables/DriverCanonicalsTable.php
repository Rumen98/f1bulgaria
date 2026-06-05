<?php

declare(strict_types=1);

namespace App\Filament\Resources\DriverCanonicals\Tables;

use App\Models\DriverCanonical;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class DriverCanonicalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('last_name')
                    ->label('Пилот')
                    ->formatStateUsing(fn (DriverCanonical $r) => $r->fullName())
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(),
                TextColumn::make('code')->label('Код'),
                TextColumn::make('total_wins')->label('Победи')->sortable(),
                TextColumn::make('total_poles')->label('Pole')->sortable(),
                TextColumn::make('total_races')->label('Старта')->sortable(),
                IconColumn::make('is_active')->label('Активен')->boolean(),
            ])
            ->defaultSort('total_wins', 'desc')
            ->filters([
                TernaryFilter::make('is_active')->label('Активен пилот'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
