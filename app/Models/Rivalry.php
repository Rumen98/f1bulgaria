<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Емблематично съперничество между двама канонични пилоти — с ера, описание и
 * запомнящи се моменти. Head-to-head данните се смятат динамично през
 * ComparisonService.
 */
class Rivalry extends Model
{
    protected $fillable = [
        'slug', 'user_id', 'driver_one_canonical_id', 'driver_two_canonical_id',
        'era_start_year', 'era_end_year', 'title_bg', 'description_bg',
        'notable_moments', 'is_featured', 'is_custom',
    ];

    protected function casts(): array
    {
        return [
            'notable_moments' => 'array',
            'is_featured' => 'boolean',
            'is_custom' => 'boolean',
            'era_start_year' => 'integer',
            'era_end_year' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<DriverCanonical, $this> */
    public function driverOne(): BelongsTo
    {
        return $this->belongsTo(DriverCanonical::class, 'driver_one_canonical_id');
    }

    /** @return BelongsTo<DriverCanonical, $this> */
    public function driverTwo(): BelongsTo
    {
        return $this->belongsTo(DriverCanonical::class, 'driver_two_canonical_id');
    }
}
