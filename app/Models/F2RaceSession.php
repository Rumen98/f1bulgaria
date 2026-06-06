<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\F2SessionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class F2RaceSession extends Model
{
    protected $fillable = [
        'f2_race_id', 'session_type', 'date', 'laps',
        'pole_position_driver_id', 'pole_position_time',
        'fastest_lap_driver_id', 'fastest_lap_time',
    ];

    protected function casts(): array
    {
        return [
            'session_type' => F2SessionType::class,
            'date' => 'date',
            'laps' => 'integer',
        ];
    }

    /** @return BelongsTo<F2Race, $this> */
    public function race(): BelongsTo
    {
        return $this->belongsTo(F2Race::class, 'f2_race_id');
    }

    /** @return HasMany<F2Result, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(F2Result::class);
    }

    /** @return BelongsTo<F2Driver, $this> */
    public function poleDriver(): BelongsTo
    {
        return $this->belongsTo(F2Driver::class, 'pole_position_driver_id');
    }

    /** @return BelongsTo<F2Driver, $this> */
    public function fastestLapDriver(): BelongsTo
    {
        return $this->belongsTo(F2Driver::class, 'fastest_lap_driver_id');
    }
}
