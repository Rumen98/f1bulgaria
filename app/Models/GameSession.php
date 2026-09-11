<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GameSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'track_slug',
        'device',
        'mode',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
