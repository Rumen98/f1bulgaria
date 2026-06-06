<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class F2Result extends Model
{
    protected $fillable = [
        'f2_race_session_id', 'f2_driver_id', 'position', 'grid_position',
        'laps_completed', 'time_or_gap', 'points', 'status', 'fastest_lap',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'grid_position' => 'integer',
            'laps_completed' => 'integer',
            'points' => 'decimal:2',
            'fastest_lap' => 'boolean',
        ];
    }

    /** @return BelongsTo<F2RaceSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(F2RaceSession::class, 'f2_race_session_id');
    }

    /** @return BelongsTo<F2Driver, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(F2Driver::class, 'f2_driver_id');
    }
}
