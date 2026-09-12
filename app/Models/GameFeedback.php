<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GameFeedbackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameFeedback extends Model
{
    /** @use HasFactory<GameFeedbackFactory> */
    use HasFactory;

    public const DIFFICULTY_LABELS = [
        'easy' => 'Твърде лесна',
        'right' => 'Точно както трябва',
        'hard' => 'Твърде трудна',
    ];

    public const REASON_LABELS = [
        'controls' => 'Трудно управление',
        'performance' => 'Забавяне / лоша графика',
        'unclear_goal' => 'Неясна цел / правила',
        'no_time' => 'Нямах достатъчно време',
        'bug' => 'Технически проблем',
        'just_trying' => 'Само я пробвах',
        'other' => 'Друго',
    ];

    public const WOULD_PLAY_AGAIN_LABELS = [
        'yes' => 'Да',
        'maybe' => 'Може би',
        'no' => 'Не',
    ];

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'session_id', 'submission_key', 'rating', 'controls_rating',
        'performance_rating', 'difficulty', 'reason', 'comment', 'would_play_again',
    ];

    /** @var list<string> */
    protected $hidden = ['submission_key'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'controls_rating' => 'integer',
            'performance_rating' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<GameSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(GameSession::class, 'session_id');
    }
}
