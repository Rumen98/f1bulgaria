<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeamNewsSources\Tables;

use App\Models\TeamNewsSource;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Artisan;

class TeamNewsSourcesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Име')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('feed_url')
                    ->label('Feed URL')
                    ->limit(40)
                    ->url(fn (TeamNewsSource $record) => $record->feed_url, shouldOpenInNewTab: true),
                TextColumn::make('constructor.name')
                    ->label('Отбор')
                    ->placeholder('Глобален'),
                ToggleColumn::make('is_active')
                    ->label('Активен'),
                TextColumn::make('last_fetched_at')
                    ->label('Последно вземане')
                    ->dateTime('d.m.Y H:i')
                    ->timezone('Europe/Sofia')
                    ->placeholder('никога')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('syncNow')
                    ->label('Опитай sync сега')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->requiresConfirmation()
                    ->modalDescription('Ще вземе feed-а веднага (live HTTP заявка) и ще запише новите елементи.')
                    ->action(function (TeamNewsSource $record) {
                        Artisan::call('news:fetch', ['--source' => $record->id]);

                        Notification::make()
                            ->title("Sync за {$record->name} приключи")
                            ->body(trim(Artisan::output()))
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
