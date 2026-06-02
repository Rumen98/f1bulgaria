<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NewsClassification;
use App\Enums\NewsStatus;
use Database\Factories\TeamNewsItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamNewsItem extends Model
{
    /** @use HasFactory<TeamNewsItemFactory> */
    use HasFactory;

    protected $fillable = [
        'source_id',
        'constructor_id',
        'external_url',
        'external_guid',
        'title_original',
        'title_bg',
        'summary_bg',
        'content_snippet',
        'published_at',
        'classification',
        'importance_score',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'classification' => NewsClassification::class,
            'status' => NewsStatus::class,
            'importance_score' => 'integer',
        ];
    }

    /** @return BelongsTo<TeamNewsSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(TeamNewsSource::class, 'source_id');
    }

    /** @return BelongsTo<Constructor, $this> */
    public function constructor(): BelongsTo
    {
        return $this->belongsTo(Constructor::class);
    }
}
