<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Един изигран куиз от влязъл потребител. Пази се за история и личен рекорд;
 * точките в класацията НЕ идват оттук (виж quiz_question_user).
 */
class QuizAttempt extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'user_id',
        'score',
        'total',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'total' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
