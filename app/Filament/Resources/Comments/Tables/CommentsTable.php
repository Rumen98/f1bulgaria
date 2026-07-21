<?php

declare(strict_types=1);

namespace App\Filament\Resources\Comments\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class CommentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->label('Потребител')->searchable()->sortable(),
                TextColumn::make('body')
                    ->label('Коментар')
                    ->searchable()
                    ->limit(90)
                    ->wrap()
                    ->tooltip(fn (TextColumn $column): ?string => $column->getState()),
                TextColumn::make('newsItem.title_bg')->label('Статия')->limit(50)->toggleable(),
                TextColumn::make('created_at')->label('Публикуван')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('deleted_at')->label('Изтрит')->dateTime('d.m.Y H:i')->placeholder('—')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TrashedFilter::make()->label('Изтрити'),
            ])
            ->recordActions([
                DeleteAction::make()->label('Скрий'),
                RestoreAction::make()->label('Възстанови'),
            ]);
    }
}
