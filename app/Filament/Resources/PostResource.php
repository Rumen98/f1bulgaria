<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PostResource\Pages\CreatePost;
use App\Filament\Resources\PostResource\Pages\EditPost;
use App\Filament\Resources\PostResource\Pages\ListPosts;
use App\Models\Post;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-newspaper';

    protected static string|\UnitEnum|null $navigationGroup = 'Съдържание';

    protected static ?string $navigationLabel = 'Постове';

    protected static ?string $modelLabel = 'пост';

    protected static ?string $pluralModelLabel = 'постове';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->label('Заглавие')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(function (string $operation, $state, Set $set) {
                    if ($operation === 'create') {
                        $set('slug', Str::slug($state));
                    }
                }),

            TextInput::make('slug')
                ->label('URL slug')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),

            MarkdownEditor::make('body_md')
                ->label('Съдържание (Markdown)')
                ->required()
                ->columnSpanFull(),

            FileUpload::make('cover_image_path')
                ->label('Корица')
                ->image()
                ->directory('posts')
                ->imageEditor(),

            Select::make('author_id')
                ->label('Автор')
                ->relationship('author', 'name')
                ->default(fn () => auth()->id())
                ->searchable()
                ->preload(),

            DateTimePicker::make('published_at')
                ->label('Публикувано на')
                ->helperText('Празно = чернова. Бъдеща дата = насрочена публикация.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Заглавие')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('author.name')
                    ->label('Автор')
                    ->sortable(),
                IconColumn::make('published')
                    ->label('Публикуван')
                    ->state(fn (Post $record) => $record->isPublished())
                    ->boolean(),
                TextColumn::make('published_at')
                    ->label('Дата')
                    ->dateTime('d.m.Y H:i')
                    ->timezone('Europe/Sofia')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('published')
                    ->label('Само публикувани')
                    ->queries(
                        true: fn ($query) => $query->published(),
                        false: fn ($query) => $query->whereNull('published_at'),
                    ),
            ])
            ->defaultSort('published_at', 'desc')
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
            'index' => ListPosts::route('/'),
            'create' => CreatePost::route('/create'),
            'edit' => EditPost::route('/{record}/edit'),
        ];
    }
}
