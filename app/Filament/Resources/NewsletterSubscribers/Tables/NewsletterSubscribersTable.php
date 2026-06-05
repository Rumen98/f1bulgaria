<?php

declare(strict_types=1);

namespace App\Filament\Resources\NewsletterSubscribers\Tables;

use App\Models\NewsletterSubscriber;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NewsletterSubscribersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')->label('Имейл')->searchable()->sortable(),
                TextColumn::make('source')->label('Източник')->badge(),
                IconColumn::make('confirmed_at')->label('Потвърден')->boolean(),
                IconColumn::make('unsubscribed_at')
                    ->label('Отписан')
                    ->boolean()
                    ->getStateUsing(fn (NewsletterSubscriber $r) => $r->unsubscribed_at !== null),
                TextColumn::make('subscribed_at')->label('Записан на')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->defaultSort('subscribed_at', 'desc')
            ->filters([
                SelectFilter::make('source')->label('Източник')->options([
                    'homepage' => 'homepage',
                    'footer' => 'footer',
                    'profile' => 'profile',
                ]),
                Filter::make('confirmed')->label('Потвърдени')
                    ->query(fn (Builder $q) => $q->whereNotNull('confirmed_at')),
                Filter::make('unconfirmed')->label('Непотвърдени')
                    ->query(fn (Builder $q) => $q->whereNull('confirmed_at')),
                Filter::make('unsubscribed')->label('Отписани')
                    ->query(fn (Builder $q) => $q->whereNotNull('unsubscribed_at')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('exportCsv')
                        ->label('Експорт CSV')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(fn (Collection $records): StreamedResponse => self::exportCsv($records)),
                ]),
            ]);
    }

    private static function exportCsv(Collection $records): StreamedResponse
    {
        return response()->streamDownload(function () use ($records) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['email', 'source', 'subscribed_at', 'confirmed_at', 'unsubscribed_at']);

            foreach ($records as $r) {
                fputcsv($out, [
                    $r->email,
                    $r->source,
                    $r->subscribed_at?->toDateTimeString(),
                    $r->confirmed_at?->toDateTimeString(),
                    $r->unsubscribed_at?->toDateTimeString(),
                ]);
            }

            fclose($out);
        }, 'newsletter-subscribers.csv', ['Content-Type' => 'text/csv']);
    }
}
