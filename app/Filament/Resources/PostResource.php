<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PostResource\Pages;
use App\Models\Post;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $navigationIcon = 'heroicon-o-newspaper';

    protected static ?string $navigationGroup = 'Съдържание';

    protected static ?string $navigationLabel = 'Постове';

    protected static ?string $modelLabel = 'пост';

    protected static ?string $pluralModelLabel = 'постове';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')
                ->label('Заглавие')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(function (string $operation, $state, Forms\Set $set) {
                    if ($operation === 'create') {
                        $set('slug', Str::slug($state));
                    }
                }),

            Forms\Components\TextInput::make('slug')
                ->label('URL slug')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),

            Forms\Components\MarkdownEditor::make('body_md')
                ->label('Съдържание (Markdown)')
                ->required()
                ->columnSpanFull(),

            Forms\Components\FileUpload::make('cover_image_path')
                ->label('Корица')
                ->image()
                ->directory('posts')
                ->imageEditor(),

            Forms\Components\Select::make('author_id')
                ->label('Автор')
                ->relationship('author', 'name')
                ->default(fn () => auth()->id())
                ->searchable()
                ->preload(),

            Forms\Components\DateTimePicker::make('published_at')
                ->label('Публикувано на')
                ->helperText('Празно = чернова. Бъдеща дата = насрочена публикация.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Заглавие')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('author.name')
                    ->label('Автор')
                    ->sortable(),
                Tables\Columns\IconColumn::make('published')
                    ->label('Публикуван')
                    ->state(fn (Post $record) => $record->isPublished())
                    ->boolean(),
                Tables\Columns\TextColumn::make('published_at')
                    ->label('Дата')
                    ->dateTime('d.m.Y H:i')
                    ->timezone('Europe/Sofia')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('published')
                    ->label('Само публикувани')
                    ->queries(
                        true: fn ($query) => $query->published(),
                        false: fn ($query) => $query->whereNull('published_at'),
                    ),
            ])
            ->defaultSort('published_at', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPosts::route('/'),
            'create' => Pages\CreatePost::route('/create'),
            'edit' => Pages\EditPost::route('/{record}/edit'),
        ];
    }
}
