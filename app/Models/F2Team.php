<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class F2Team extends Model
{
    protected $fillable = ['f2_season_id', 'name', 'slug', 'color_hex'];

    /** @return BelongsTo<F2Season, $this> */
    public function season(): BelongsTo
    {
        return $this->belongsTo(F2Season::class, 'f2_season_id');
    }

    /** @return HasMany<F2Driver, $this> */
    public function drivers(): HasMany
    {
        return $this->hasMany(F2Driver::class, 'f2_team_id');
    }
}
