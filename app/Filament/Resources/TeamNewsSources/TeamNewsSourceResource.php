<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeamNewsSources;

use App\Filament\Resources\TeamNewsSources\Pages\CreateTeamNewsSource;
use App\Filament\Resources\TeamNewsSources\Pages\EditTeamNewsSource;
use App\Filament\Resources\TeamNewsSources\Pages\ListTeamNewsSources;
use App\Filament\Resources\TeamNewsSources\Schemas\TeamNewsSourceForm;
use App\Filament\Resources\TeamNewsSources\Tables\TeamNewsSourcesTable;
use App\Models\TeamNewsSource;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class TeamNewsSourceResource extends Resource
{
    protected static ?string $model = TeamNewsSource::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rss';

    protected static string|\UnitEnum|null $navigationGroup = 'Новини';

    protected static ?string $navigationLabel = 'Източници';

    protected static ?string $modelLabel = 'източник';

    protected static ?string $pluralModelLabel = 'източници';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return TeamNewsSourceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TeamNewsSourcesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeamNewsSources::route('/'),
            'create' => CreateTeamNewsSource::route('/create'),
            'edit' => EditTeamNewsSource::route('/{record}/edit'),
        ];
    }
}
