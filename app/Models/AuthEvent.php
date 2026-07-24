<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Едно събитие от одит лога на автентикацията.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $email
 * @property string $type
 * @property string|null $ip_address
 * @property string|null $user_agent
 */
class AuthEvent extends Model
{
    public const TYPE_LOGIN = 'login';

    public const TYPE_LOGOUT = 'logout';

    public const TYPE_REGISTERED = 'registered';

    public const TYPE_FAILED = 'failed';

    /** Български етикети за типовете (отчет + Filament). */
    public const LABELS = [
        self::TYPE_REGISTERED => 'Регистрация',
        self::TYPE_LOGIN => 'Влизане',
        self::TYPE_LOGOUT => 'Изход',
        self::TYPE_FAILED => 'Неуспешен опит',
    ];

    /** @var array<int, string> */
    protected $fillable = [
        'user_id',
        'email',
        'type',
        'ip_address',
        'user_agent',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
