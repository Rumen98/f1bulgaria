<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeamNewsItems\Tables;

use App\Enums\NewsClassification;
use App\Enums\NewsStatus;
use App\Models\TeamNewsItem;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class TeamNewsItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('published_at')
                    ->label('Публикувано')
                    ->dateTime('d.m.Y H:i')
                    ->timezone('Europe/Sofia')
                    ->sortable(),
                TextColumn::make('title_original')
                    ->label('Оригинал')
                    ->searchable()
                    ->limit(45)
                    ->wrap(),
                TextColumn::make('title_bg')
                    ->label('Заглавие (BG)')
                    ->searchable()
                    ->limit(45)
                    ->wrap(),
                // Скрита по подразбиране, но търсима — за да покрием summary_bg в search.
                TextColumn::make('summary_bg')
                    ->label('Резюме (BG)')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('constructor.name')
                    ->label('Отбор')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('classification')
                    ->label('Категория')
                    ->badge()
                    ->formatStateUsing(fn (?NewsClassification $state) => $state?->label()),
                TextColumn::make('importance_score')
                    ->label('Важност')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (?NewsStatus $state) => $state?->label())
                    ->color(fn (?NewsStatus $state) => match ($state) {
                        NewsStatus::Approved, NewsStatus::AutoPublished => 'success',
                        NewsStatus::Rejected => 'danger',
                        default => 'warning',
                    }),
            ])
            ->defaultSort('published_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->multiple()
                    ->options(self::enumOptions(NewsStatus::cases())),
                SelectFilter::make('classification')
                    ->label('Категория')
                    ->multiple()
                    ->options(self::enumOptions(NewsClassification::cases())),
                SelectFilter::make('constructor')
                    ->label('Отбор')
                    ->relationship('constructor', 'name'),
                SelectFilter::make('source')
                    ->label('Източник')
                    ->relationship('source', 'name'),
                Filter::make('importance')
                    ->schema([
                        TextInput::make('imp_min')->label('Важност от')->numeric()->minValue(1)->maxValue(5),
                        TextInput::make('imp_max')->label('до')->numeric()->minValue(1)->maxValue(5),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['imp_min'] ?? null, fn (Builder $q, $v) => $q->where('importance_score', '>=', $v))
                        ->when($data['imp_max'] ?? null, fn (Builder $q, $v) => $q->where('importance_score', '<=', $v))),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Одобри')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (TeamNewsItem $record) => $record->status !== NewsStatus::Approved)
                    ->action(fn (TeamNewsItem $record) => $record->update(['status' => NewsStatus::Approved->value])),
                Action::make('reject')
                    ->label('Откажи')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (TeamNewsItem $record) => $record->update(['status' => NewsStatus::Rejected->value])),
                Action::make('reEnrich')
                    ->label('Re-enrich')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->modalDescription('Нулира ВСИЧКИ български полета (включително пълната статия и анализа) и връща статус „Чакаща“. Следващият news:enrich цикъл ще ги генерира наново.')
                    ->action(fn (TeamNewsItem $record) => $record->update([
                        'title_bg' => null,
                        'summary_bg' => null,
                        'classification' => null,
                        'constructor_id' => null,
                        'importance_score' => null,
                        // Enrich пише статията наново — нулираме ги, за да е
                        // явно, че ръчните редакции тук се губят.
                        'full_article_bg' => null,
                        'our_analysis_bg' => null,
                        'key_facts' => null,
                        'status' => NewsStatus::Pending->value,
                    ])),
                Action::make('viewOriginal')
                    ->label('Виж оригинала')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (TeamNewsItem $record) => $record->external_url)
                    ->openUrlInNewTab(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('approveSelected')
                        ->label('Одобри избраните')
                        ->icon('heroicon-o-check')
                        ->color('success')
                        ->action(fn (Collection $records) => $records->each->update(['status' => NewsStatus::Approved->value]))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('rejectSelected')
                        ->label('Откажи избраните')
                        ->icon('heroicon-o-x-mark')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each->update(['status' => NewsStatus::Rejected->value]))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    /**
     * @param  array<int, NewsStatus|NewsClassification>  $cases
     * @return array<string, string>
     */
    private static function enumOptions(array $cases): array
    {
        return collect($cases)->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
    }
}
