<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PredictionScoreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PredictionScore extends Model
{
    /** @use HasFactory<PredictionScoreFactory> */
    use HasFactory;

    protected $fillable = [
        'prediction_id',
        'points',
        'breakdown_json',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'breakdown_json' => 'array',
        ];
    }

    /** @return BelongsTo<Prediction, $this> */
    public function prediction(): BelongsTo
    {
        return $this->belongsTo(Prediction::class);
    }
}
