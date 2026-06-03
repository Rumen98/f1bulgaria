<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DriverFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Driver extends Model
{
    /** @use HasFactory<DriverFactory> */
    use HasFactory;

    protected $fillable = [
        'season_id',
        'constructor_id',
        'jolpica_id',
        'driver_code',
        'first_name',
        'last_name',
        'slug',
        'permanent_number',
        'country_code',
        'photo_url',
    ];

    protected function casts(): array
    {
        return [
            'permanent_number' => 'integer',
        ];
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /** @return BelongsTo<Season, $this> */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /** @return BelongsTo<Constructor, $this> */
    public function constructor(): BelongsTo
    {
        return $this->belongsTo(Constructor::class);
    }

    /** @return HasMany<Result, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(Result::class);
    }
}
