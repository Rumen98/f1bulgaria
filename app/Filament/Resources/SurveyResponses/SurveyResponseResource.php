<?php

declare(strict_types=1);

namespace App\Filament\Resources\SurveyResponses;

use App\Filament\Resources\SurveyResponses\Pages\ListSurveyResponses;
use App\Filament\Resources\SurveyResponses\Tables\SurveyResponsesTable;
use App\Models\SurveyResponse;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SurveyResponseResource extends Resource
{
    protected static ?string $model = SurveyResponse::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|\UnitEnum|null $navigationGroup = 'Общност';

    protected static ?string $navigationLabel = 'Обратна връзка';

    protected static ?string $modelLabel = 'отговор';

    protected static ?string $pluralModelLabel = 'отговори';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return SurveyResponsesTable::configure($table);
    }

    /**
     * Само списък — отговорите идват от анкетата на сайта, не се добавят ръчно.
     */
    public static function getPages(): array
    {
        return [
            'index' => ListSurveyResponses::route('/'),
        ];
    }
}
