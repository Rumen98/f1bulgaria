<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GameLapRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameLapRecord extends Model
{
    /** @use HasFactory<GameLapRecordFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'track_slug',
        'lap_ms',
        'sector1_ms',
        'sector2_ms',
        'sector3_ms',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'lap_ms' => 'integer',
            'sector1_ms' => 'integer',
            'sector2_ms' => 'integer',
            'sector3_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
