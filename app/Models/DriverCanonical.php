<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DriverCanonicalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Каноничен пилот — един запис за човек през всички сезони (за разлика от
 * per-season `drivers`). Идентичността е по slug; кодът е атрибут.
 */
class DriverCanonical extends Model
{
    /** @use HasFactory<DriverCanonicalFactory> */
    use HasFactory;

    protected $table = 'drivers_canonical';

    protected $fillable = [
        'code', 'first_name', 'last_name', 'slug', 'country_code', 'date_of_birth',
        'permanent_number', 'photo_url', 'bio_bg', 'is_active',
        'total_wins', 'total_podiums', 'total_poles', 'total_races',
        'first_race_at', 'last_race_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'date_of_birth' => 'date',
            'first_race_at' => 'date',
            'last_race_at' => 'date',
            'permanent_number' => 'integer',
            'total_wins' => 'integer',
            'total_podiums' => 'integer',
            'total_poles' => 'integer',
            'total_races' => 'integer',
        ];
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /** @return HasMany<Driver, $this> per-season записи */
    public function seasons(): HasMany
    {
        return $this->hasMany(Driver::class, 'canonical_id');
    }
}
