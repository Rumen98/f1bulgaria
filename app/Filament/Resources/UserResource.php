<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Общност';

    protected static ?string $navigationLabel = 'Потребители';

    protected static ?string $modelLabel = 'потребител';

    protected static ?string $pluralModelLabel = 'потребители';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Име')
                ->required(),
            Forms\Components\TextInput::make('email')
                ->label('Имейл')
                ->email()
                ->required()
                ->unique(ignoreRecord: true),
            Forms\Components\TextInput::make('password')
                ->label('Парола')
                ->password()
                ->dehydrateStateUsing(fn (?string $state) => filled($state) ? Hash::make($state) : null)
                ->dehydrated(fn (?string $state) => filled($state))
                ->required(fn (string $operation) => $operation === 'create'),
            Forms\Components\Toggle::make('is_admin')
                ->label('Администратор'),
            Forms\Components\DateTimePicker::make('banned_at')
                ->label('Блокиран на')
                ->helperText('Попълнено = потребителят е блокиран и не може да влиза.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Име')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('email')->label('Имейл')->searchable(),
                Tables\Columns\IconColumn::make('is_admin')->label('Админ')->boolean(),
                Tables\Columns\IconColumn::make('banned_at')
                    ->label('Блокиран')
                    ->state(fn (User $record) => $record->isBanned())
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('success'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Регистриран')
                    ->date('d.m.Y')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('banned_at')
                    ->label('Блокирани')
                    ->nullable(),
            ])
            ->actions([
                // Бърза модерация без отваряне на формата.
                Tables\Actions\Action::make('toggleBan')
                    ->label(fn (User $record) => $record->isBanned() ? 'Отблокирай' : 'Блокирай')
                    ->icon('heroicon-o-no-symbol')
                    ->color(fn (User $record) => $record->isBanned() ? 'success' : 'danger')
                    ->requiresConfirmation()
                    ->action(fn (User $record) => $record->update([
                        'banned_at' => $record->isBanned() ? null : now(),
                    ])),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
