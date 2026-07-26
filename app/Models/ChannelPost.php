<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChannelPostKind;
use App\Enums\ChannelPostStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Един ред в изходящата опашка към канала.
 *
 * @property ChannelPostKind $kind
 * @property ChannelPostStatus $status
 */
class ChannelPost extends Model
{
    protected $fillable = [
        'channel',
        'kind',
        'subject_type',
        'subject_id',
        'body',
        'status',
        'attempts',
        'last_error',
        'telegram_message_id',
        'available_at',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ChannelPostKind::class,
            'status' => ChannelPostStatus::class,
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Готови за изпращане, в реда на постъпване.
     *
     * `available_at` в бъдещето означава съзнателно отлагане (напр. изчакване
     * на стюардите след състезание), затова тези редове се пропускат.
     *
     * @param  Builder<ChannelPost>  $query
     */
    public function scopeReady(Builder $query): void
    {
        $query->where('status', ChannelPostStatus::Pending->value)
            ->where(function (Builder $q): void {
                $q->whereNull('available_at')->orWhere('available_at', '<=', now());
            })
            ->orderBy('available_at')
            ->orderBy('id');
    }
}
