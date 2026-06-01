<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SessionType;
use Database\Factories\RaceSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RaceSession extends Model
{
    /** @use HasFactory<RaceSessionFactory> */
    use HasFactory;

    protected $fillable = [
        'race_id',
        'type',
        'scheduled_at_utc',
    ];

    protected function casts(): array
    {
        return [
            'type' => SessionType::class,
            'scheduled_at_utc' => 'datetime',
        ];
    }

    /** @return BelongsTo<Race, $this> */
    public function race(): BelongsTo
    {
        return $this->belongsTo(Race::class);
    }
}
