<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PredictionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Prediction extends Model
{
    /** @use HasFactory<PredictionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'race_id',
        'p1_driver_id',
        'p2_driver_id',
        'p3_driver_id',
        'pole_driver_id',
        'fastest_lap_driver_id',
        'dnf_count',
        'safety_car',
        'locked_at',
    ];

    protected function casts(): array
    {
        return [
            'dnf_count' => 'integer',
            'safety_car' => 'boolean',
            'locked_at' => 'datetime',
        ];
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null && $this->locked_at->isPast();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Race, $this> */
    public function race(): BelongsTo
    {
        return $this->belongsTo(Race::class);
    }

    /** @return BelongsTo<Driver, $this> */
    public function p1Driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'p1_driver_id');
    }

    /** @return BelongsTo<Driver, $this> */
    public function p2Driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'p2_driver_id');
    }

    /** @return BelongsTo<Driver, $this> */
    public function p3Driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'p3_driver_id');
    }

    /** @return BelongsTo<Driver, $this> */
    public function poleDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'pole_driver_id');
    }

    /** @return BelongsTo<Driver, $this> */
    public function fastestLapDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'fastest_lap_driver_id');
    }

    /** @return HasOne<PredictionScore, $this> */
    public function score(): HasOne
    {
        return $this->hasOne(PredictionScore::class);
    }
}
