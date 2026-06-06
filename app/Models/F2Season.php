<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class F2Season extends Model
{
    protected $fillable = ['year', 'is_current'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'is_current' => 'boolean'];
    }

    public static function current(): ?self
    {
        return static::query()->where('is_current', true)->first()
            ?? static::query()->orderByDesc('year')->first();
    }

    /** @return HasMany<F2Team, $this> */
    public function teams(): HasMany
    {
        return $this->hasMany(F2Team::class);
    }

    /** @return HasMany<F2Driver, $this> */
    public function drivers(): HasMany
    {
        return $this->hasMany(F2Driver::class);
    }
}
