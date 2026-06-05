<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConstructorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Constructor extends Model
{
    /** @use HasFactory<ConstructorFactory> */
    use HasFactory;

    protected $fillable = [
        'season_id',
        'canonical_id',
        'jolpica_id',
        'name',
        'slug',
        'color_hex',
    ];

    /** @return BelongsTo<Season, $this> */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /** @return BelongsTo<ConstructorCanonical, $this> */
    public function canonical(): BelongsTo
    {
        return $this->belongsTo(ConstructorCanonical::class, 'canonical_id');
    }

    /** @return HasMany<Driver, $this> */
    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }

    /** @return HasMany<TeamNewsSource, $this> */
    public function newsSources(): HasMany
    {
        return $this->hasMany(TeamNewsSource::class);
    }

    /** @return HasMany<TeamNewsItem, $this> */
    public function newsItems(): HasMany
    {
        return $this->hasMany(TeamNewsItem::class);
    }
}
