<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SeasonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Season extends Model
{
    /** @use HasFactory<SeasonFactory> */
    use HasFactory;

    protected $fillable = [
        'year',
        'is_current',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'is_current' => 'boolean',
        ];
    }

    /** @return HasMany<Race, $this> */
    public function races(): HasMany
    {
        return $this->hasMany(Race::class);
    }

    /** @return HasMany<Driver, $this> */
    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }

    /** @return HasMany<Constructor, $this> */
    public function constructors(): HasMany
    {
        return $this->hasMany(Constructor::class);
    }

    public static function current(): ?self
    {
        return static::query()->where('is_current', true)->first();
    }
}
