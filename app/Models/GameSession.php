<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GameSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Едно натискане на „Карай" от регистриран потребител — следата „пробвал е
 * играта", независимо дали е завършил обиколка.
 *
 * @property int $id
 * @property int $user_id
 * @property string $track_slug
 * @property string $device
 * @property string $mode
 */
class GameSession extends Model
{
    /** @use HasFactory<GameSessionFactory> */
    use HasFactory;

    public const DEVICE_MOBILE = 'mobile';

    public const DEVICE_DESKTOP = 'desktop';

    public const MODE_SOLO = 'solo';

    public const MODE_RACE = 'race';

    /** Български етикети за Filament. */
    public const DEVICE_LABELS = [
        self::DEVICE_MOBILE => 'Телефон',
        self::DEVICE_DESKTOP => 'Компютър',
    ];

    public const MODE_LABELS = [
        self::MODE_SOLO => 'Сам на пистата',
        self::MODE_RACE => 'Състезание',
    ];

    /** Етикетите за админа живеят на едно място — GameSessionPresentation. */
    public const TERMINAL_STATUSES = ['completed', 'quit', 'restarted', 'interrupted', 'error'];

    protected $attributes = [
        'status' => 'legacy',
        'active_ms' => 0,
        'last_sequence' => 0,
        'lap_count' => 0,
        'valid_lap_count' => 0,
        'invalid_lap_count' => 0,
        'max_progress' => 0,
        'max_speed' => 0,
    ];

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'track_slug',
        'device',
        'mode',
        'client_id',
        'status',
        'context',
        'active_ms',
        'last_sequence',
        'last_seen_at',
        'ended_at',
        'lap_count',
        'valid_lap_count',
        'invalid_lap_count',
        'max_progress',
        'max_speed',
        'last_sector',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'active_ms' => 'integer',
            'last_sequence' => 'integer',
            'last_seen_at' => 'datetime',
            'ended_at' => 'datetime',
            'lap_count' => 'integer',
            'valid_lap_count' => 'integer',
            'invalid_lap_count' => 'integer',
            'max_progress' => 'float',
            'max_speed' => 'float',
            'last_sector' => 'integer',
        ];
    }

    /**
     * Без ORDER BY в релацията: таблицата на събитията сортира сама, а
     * вграден ред би обезсилил сортирането на админа.
     *
     * @return HasMany<GameSessionEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(GameSessionEvent::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
