<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Race extends Model
{
    /** @use HasFactory<RaceFactory> */
    use HasFactory;

    protected $fillable = [
        'season_id',
        'jolpica_id',
        'round',
        'name',
        'circuit',
        'country',
        'race_datetime_utc',
        'qualifying_datetime_utc',
        'sprint_datetime_utc',
        'has_sprint',
        'pole_driver_id',
        'had_safety_car',
    ];

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'race_datetime_utc' => 'datetime',
            'qualifying_datetime_utc' => 'datetime',
            'sprint_datetime_utc' => 'datetime',
            'has_sprint' => 'boolean',
            'had_safety_car' => 'boolean',
        ];
    }

    /** @return BelongsTo<Season, $this> */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /** @return BelongsTo<Driver, $this> */
    public function poleDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'pole_driver_id');
    }

    /** @return HasMany<RaceSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(RaceSession::class);
    }

    /** @return HasMany<Result, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(Result::class);
    }

    /** @return HasMany<Prediction, $this> */
    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class);
    }

    public function hasFinished(): bool
    {
        return $this->results()->exists();
    }

    public function isUpcoming(): bool
    {
        return $this->race_datetime_utc !== null
            && $this->race_datetime_utc->isFuture();
    }
}
