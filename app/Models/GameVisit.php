<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GameVisitFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Едно отваряне на играта (view) или гост-„Карай" (start). За регистрирани
 * стартовете са в game_sessions; тук за тях остават само отварянията.
 *
 * @property int $id
 * @property string $visitor_key
 * @property int|null $user_id
 * @property string $kind
 * @property string $device
 * @property string|null $track_slug
 * @property Carbon $created_at
 */
class GameVisit extends Model
{
    /** @use HasFactory<GameVisitFactory> */
    use HasFactory, Prunable;

    /** Статистиката гледа до 30 дни назад; година е достатъчна за сравнение по сезони. */
    public const RETENTION_DAYS = 365;

    public const KIND_VIEW = 'view';

    public const KIND_START = 'start';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['visitor_key', 'user_id', 'kind', 'device', 'track_slug', 'created_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
