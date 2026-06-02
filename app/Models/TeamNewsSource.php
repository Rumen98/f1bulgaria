<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TeamNewsSourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeamNewsSource extends Model
{
    /** @use HasFactory<TeamNewsSourceFactory> */
    use HasFactory;

    protected $fillable = [
        'constructor_id',
        'name',
        'feed_url',
        'type',
        'language',
        'is_active',
        'last_fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_fetched_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Constructor, $this> */
    public function constructor(): BelongsTo
    {
        return $this->belongsTo(Constructor::class);
    }

    /** @return HasMany<TeamNewsItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(TeamNewsItem::class, 'source_id');
    }

    public function isGlobal(): bool
    {
        return $this->constructor_id === null;
    }
}
