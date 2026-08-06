<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\QuizQuestionResource\Pages\CreateQuizQuestion;
use App\Filament\Resources\QuizQuestionResource\Pages\EditQuizQuestion;
use App\Filament\Resources\QuizQuestionResource\Pages\ListQuizQuestions;
use App\Models\QuizQuestion;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class QuizQuestionResource extends Resource
{
    protected static ?string $model = QuizQuestion::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static string|\UnitEnum|null $navigationGroup = 'Съдържание';

    protected static ?string $navigationLabel = 'Куиз въпроси';

    protected static ?string $modelLabel = 'въпрос';

    protected static ?string $pluralModelLabel = 'въпроси';

    protected static ?string $recordTitleAttribute = 'question';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('question')
                ->label('Въпрос')
                ->required()
                ->rows(2)
                ->maxLength(500)
                ->columnSpanFull(),

            TextInput::make('option_1')->label('Опция 1')->required()->maxLength(255),
            TextInput::make('option_2')->label('Опция 2')->required()->maxLength(255),
            TextInput::make('option_3')->label('Опция 3')->required()->maxLength(255),
            TextInput::make('option_4')->label('Опция 4')->required()->maxLength(255),

            Radio::make('correct_option')
                ->label('Верен отговор')
                ->options([1 => 'Опция 1', 2 => 'Опция 2', 3 => 'Опция 3', 4 => 'Опция 4'])
                ->required()
                ->default(1)
                ->inline(),

            Toggle::make('is_active')
                ->label('Активен')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('question')
                    ->label('Въпрос')
                    ->limit(60)
                    ->searchable()
                    ->wrap(),
                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('Създаден')
                    ->dateTime('d.m.Y H:i')
                    ->timezone('Europe/Sofia')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Само активни'),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuizQuestions::route('/'),
            'create' => CreateQuizQuestion::route('/create'),
            'edit' => EditQuizQuestion::route('/{record}/edit'),
        ];
    }
}
