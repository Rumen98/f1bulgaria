<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GameLapRecordFactory;
use Illuminate\Database\Eloquent\Builder;
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
        'input_trace',
        'sim_version',
        'verify_status',
        'verified_lap_ms',
        'ghost_frames',
        'lap_ticks',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'lap_ms' => 'integer',
            'sector1_ms' => 'integer',
            'sector2_ms' => 'integer',
            'sector3_ms' => 'integer',
            'sim_version' => 'integer',
            'verified_lap_ms' => 'integer',
            'lap_ticks' => 'integer',
        ];
    }

    /**
     * Обиколките, които се броят в класацията: всичко освен отхвърлените от
     * сървърното преиграване. NULL (стари записи/без трейс) и pending/error
     * остават — не наказваме никого без доказателство.
     *
     * @param  Builder<GameLapRecord>  $query
     * @return Builder<GameLapRecord>
     */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->where(function (Builder $inner): void {
            $inner->whereNull('verify_status')->orWhere('verify_status', '!=', 'rejected');
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
