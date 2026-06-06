<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class F2Driver extends Model
{
    protected $fillable = [
        'f2_season_id', 'f2_team_id', 'first_name', 'last_name', 'slug',
        'country_code', 'position', 'points', 'is_champion',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'points' => 'float',
            'is_champion' => 'boolean',
        ];
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /** @return BelongsTo<F2Season, $this> */
    public function season(): BelongsTo
    {
        return $this->belongsTo(F2Season::class, 'f2_season_id');
    }

    /** @return BelongsTo<F2Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(F2Team::class, 'f2_team_id');
    }
}
