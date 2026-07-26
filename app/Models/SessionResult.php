<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SessionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ред от класация на сесия без шампионатни точки.
 *
 * @property SessionType $session_type
 */
class SessionResult extends Model
{
    protected $fillable = [
        'race_id',
        'session_type',
        'driver_id',
        'position',
        'best_time',
        'gap',
        'q1',
        'q2',
        'q3',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'session_type' => SessionType::class,
            'position' => 'integer',
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

    /**
     * Най-доброто време от отсечките на квалификацията.
     *
     * Показва се Q3 при тези, които са стигнали дотам, Q2 при отпадналите в
     * Q2 и т.н. — иначе класацията показва време от различни етапи и
     * подредбата ѝ изглежда грешна.
     */
    public function bestQualifyingTime(): ?string
    {
        return $this->q3 ?: ($this->q2 ?: ($this->q1 ?: $this->best_time));
    }
}
