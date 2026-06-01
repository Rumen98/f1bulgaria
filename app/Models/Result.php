<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ResultSessionType;
use Database\Factories\ResultFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Result extends Model
{
    /** @use HasFactory<ResultFactory> */
    use HasFactory;

    protected $fillable = [
        'race_id',
        'driver_id',
        'session_type',
        'jolpica_id',
        'position',
        'points',
        'dnf',
        'fastest_lap',
        'grid_position',
    ];

    protected function casts(): array
    {
        return [
            'session_type' => ResultSessionType::class,
            'position' => 'integer',
            'points' => 'decimal:2',
            'dnf' => 'boolean',
            'fastest_lap' => 'boolean',
            'grid_position' => 'integer',
        ];
    }

    /** @return BelongsTo<Race, $this> */
    public function race(): BelongsTo
    {
        return $this->belongsTo(Race::class);
    }

    /** @return BelongsTo<Driver, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
