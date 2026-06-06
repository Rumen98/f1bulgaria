<?php

declare(strict_types=1);

namespace App\Filament\Resources\Rivalries\Schemas;

use App\Models\DriverCanonical;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RivalryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Основни')->columns(2)->schema([
                TextInput::make('title_bg')->label('Заглавие')->required(),
                TextInput::make('slug')->label('Slug')->required(),
                Select::make('driver_one_canonical_id')->label('Пилот 1')
                    ->options(fn () => self::driverOptions())->searchable()->required(),
                Select::make('driver_two_canonical_id')->label('Пилот 2')
                    ->options(fn () => self::driverOptions())->searchable()->required(),
                TextInput::make('era_start_year')->label('Начална година')->numeric(),
                TextInput::make('era_end_year')->label('Крайна година')->numeric(),
                Toggle::make('is_featured')->label('Топ (на видно място)'),
                Textarea::make('description_bg')->label('Описание (BG)')->rows(4)->columnSpanFull(),
            ]),

            Section::make('Запомнящи се моменти')->schema([
                Repeater::make('notable_moments')->label('Моменти')->schema([
                    TextInput::make('year')->label('Година')->numeric()->required(),
                    TextInput::make('description')->label('Описание')->required(),
                ])->columns(2)->defaultItems(0),
            ]),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private static function driverOptions(): array
    {
        return DriverCanonical::query()
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn (DriverCanonical $c) => [$c->id => $c->fullName()])
            ->all();
    }
}
