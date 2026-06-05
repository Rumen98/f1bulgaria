<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Абонат за бюлетина. Събираме имейли сега, изпращаме по-късно (SMTP още не е
 * конфигуриран). Double opt-in scaffold: confirmation_token → confirmed_at.
 */
class NewsletterSubscriber extends Model
{
    protected $fillable = [
        'email', 'subscribed_at', 'unsubscribed_at', 'source',
        'confirmation_token', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'subscribed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * Активни абонати: потвърдени И неотписани.
     *
     * @param  Builder<NewsletterSubscriber>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNotNull('confirmed_at')->whereNull('unsubscribed_at');
    }
}
