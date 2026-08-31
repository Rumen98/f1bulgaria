<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WouldRecommend;
use Database\Factories\SurveyResponseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurveyResponse extends Model
{
    /** @use HasFactory<SurveyResponseFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'rating',
        'would_recommend',
        'comment',
        'source',
        'submitted_at',
        'dismissed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'would_recommend' => WouldRecommend::class,
            'submitted_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
