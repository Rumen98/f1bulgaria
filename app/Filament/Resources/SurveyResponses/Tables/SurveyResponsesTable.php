<?php

declare(strict_types=1);

namespace App\Filament\Resources\SurveyResponses\Tables;

use App\Enums\WouldRecommend;
use App\Models\SurveyResponse;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SurveyResponsesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->label('Потребител')->searchable()->sortable(),
                TextColumn::make('rating')->label('Оценка')->sortable()->placeholder('—'),
                TextColumn::make('would_recommend')
                    ->label('Би препоръчал')
                    ->formatStateUsing(fn (?WouldRecommend $state): ?string => $state?->label())
                    ->placeholder('—'),
                TextColumn::make('comment')
                    ->label('Коментар')
                    ->searchable()
                    ->limit(90)
                    ->wrap()
                    ->tooltip(fn (TextColumn $column): ?string => $column->getState())
                    ->placeholder('—'),
                TextColumn::make('source')->label('Източник')->badge(),
                IconColumn::make('submitted_at')
                    ->label('Изпратен')
                    ->boolean()
                    ->getStateUsing(fn (SurveyResponse $r) => $r->submitted_at !== null),
                TextColumn::make('created_at')
                    ->label('Кога')
                    ->dateTime('d.m.Y H:i')
                    ->timezone('Europe/Sofia')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                // Редовете само от скриване на картата са шум при четене на мненията.
                Filter::make('submitted')->label('Само изпратени')
                    ->query(fn (Builder $q) => $q->whereNotNull('submitted_at')),
                SelectFilter::make('rating')->label('Оценка')->options([
                    5 => '5',
                    4 => '4',
                    3 => '3',
                    2 => '2',
                    1 => '1',
                ]),
            ])
            // Изтриване за модерация на спам/обиден текст (създаване остава
            // забранено). Твърдо изтриване — редовете нямат SoftDeletes.
            ->recordActions([
                DeleteAction::make()->label('Изтрий'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->label('Изтрий избраните'),
                ]),
            ]);
    }
}
