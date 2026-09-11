<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|\UnitEnum|null $navigationGroup = 'Общност';

    protected static ?string $navigationLabel = 'Потребители';

    protected static ?string $modelLabel = 'потребител';

    protected static ?string $pluralModelLabel = 'потребители';

    /**
     * Точният брой потребители до пункта в менюто. На телефон обобщението
     * под таблицата („Показани 1 до 25 от 40") се свива и числото изчезва —
     * значката се вижда винаги.
     */
    public static function getNavigationBadge(): ?string
    {
        return (string) User::count();
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Общо регистрирани потребители';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Име')
                ->required(),
            TextInput::make('email')
                ->label('Имейл')
                ->email()
                ->required()
                ->unique(ignoreRecord: true),
            TextInput::make('password')
                ->label('Парола')
                ->password()
                ->dehydrateStateUsing(fn (?string $state) => filled($state) ? Hash::make($state) : null)
                ->dehydrated(fn (?string $state) => filled($state))
                ->required(fn (string $operation) => $operation === 'create'),
            Toggle::make('is_admin')
                ->label('Администратор'),
            DateTimePicker::make('banned_at')
                ->label('Блокиран на')
                ->helperText('Попълнено = потребителят е блокиран и не може да влиза.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Име')->searchable()->sortable(),
                TextColumn::make('email')->label('Имейл')->searchable(),
                IconColumn::make('is_admin')->label('Админ')->boolean(),
                IconColumn::make('banned_at')
                    ->label('Блокиран')
                    ->state(fn (User $record) => $record->isBanned())
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('success'),
                TextColumn::make('created_at')
                    ->label('Регистриран')
                    ->date('d.m.Y')
                    ->sortable(),
            ])
            // Без това таблицата излизаше в реда на базата и трябваше да се
            // цъка колона, за да се получи смислена подредба. Най-новите
            // регистрации първи — както е в останалите ресурси.
            ->defaultSort('created_at', 'desc')
            // При размера на общността всички се събират на една страница,
            // вместо да се прелиства по десет. „all" остава като опция.
            ->paginationPageOptions([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50)
            ->filters([
                TernaryFilter::make('banned_at')
                    ->label('Блокирани')
                    ->nullable(),
            ])
            ->recordActions([
                // Бърза модерация без отваряне на формата.
                Action::make('toggleBan')
                    ->label(fn (User $record) => $record->isBanned() ? 'Отблокирай' : 'Блокирай')
                    ->icon('heroicon-o-no-symbol')
                    ->color(fn (User $record) => $record->isBanned() ? 'success' : 'danger')
                    ->requiresConfirmation()
                    ->action(fn (User $record) => $record->update([
                        'banned_at' => $record->isBanned() ? null : now(),
                    ])),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
