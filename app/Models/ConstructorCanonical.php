<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Каноничен конструктор — един запис за отбор през всички сезони (за разлика от
 * per-season `constructors`). Идентичността е по slug.
 */
class ConstructorCanonical extends Model
{
    protected $table = 'constructors_canonical';

    protected $fillable = [
        'name', 'slug', 'color_hex', 'logo_url', 'bio_bg', 'is_active',
        'total_wins', 'total_podiums', 'total_poles', 'total_races',
        'first_race_at', 'last_race_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'first_race_at' => 'date',
            'last_race_at' => 'date',
            'total_wins' => 'integer',
            'total_podiums' => 'integer',
            'total_poles' => 'integer',
            'total_races' => 'integer',
        ];
    }

    /** @return HasMany<Constructor, $this> per-season записи */
    public function seasons(): HasMany
    {
        return $this->hasMany(Constructor::class, 'canonical_id');
    }
}
