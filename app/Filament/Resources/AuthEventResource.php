<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AuthEventResource\Pages\ListAuthEvents;
use App\Models\AuthEvent;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Одит лог на автентикацията — само за четене (append-only).
 */
class AuthEventResource extends Resource
{
    protected static ?string $model = AuthEvent::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-finger-print';

    protected static string|\UnitEnum|null $navigationGroup = 'Общност';

    protected static ?string $navigationLabel = 'Одит лог';

    protected static ?string $modelLabel = 'събитие';

    protected static ?string $pluralModelLabel = 'одит лог';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Кога')
                    ->dateTime('d.m.Y H:i')
                    ->timezone('Europe/Sofia')
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Събитие')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AuthEvent::LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        AuthEvent::TYPE_REGISTERED => 'success',
                        AuthEvent::TYPE_LOGIN => 'info',
                        AuthEvent::TYPE_FAILED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('email')
                    ->label('Имейл')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('ip_address')
                    ->label('IP')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('user_agent')
                    ->label('Устройство')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Тип')
                    ->options(AuthEvent::LABELS),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuthEvents::route('/'),
        ];
    }
}
