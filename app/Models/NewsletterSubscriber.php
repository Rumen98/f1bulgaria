<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Абонат за бюлетина. Записването е директно активно (без double opt-in);
 * unsubscribe_token е линкът за отписване във всяко писмо.
 */
class NewsletterSubscriber extends Model
{
    protected $fillable = [
        'email', 'subscribed_at', 'unsubscribed_at', 'source', 'unsubscribe_token',
    ];

    protected function casts(): array
    {
        return [
            'subscribed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    /**
     * Активни абонати: всички неотписани.
     *
     * @param  Builder<NewsletterSubscriber>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('unsubscribed_at');
    }
}
