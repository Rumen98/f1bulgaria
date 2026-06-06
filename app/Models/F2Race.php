<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class F2Race extends Model
{
    protected $fillable = [
        'f2_season_id', 'circuit_jolpica_id', 'location_name', 'round',
        'race_datetime_utc', 'wikipedia_url', 'slug',
    ];

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'race_datetime_utc' => 'datetime',
        ];
    }

    /** @return BelongsTo<F2Season, $this> */
    public function season(): BelongsTo
    {
        return $this->belongsTo(F2Season::class, 'f2_season_id');
    }

    /** @return HasMany<F2RaceSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(F2RaceSession::class);
    }
}
