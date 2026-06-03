<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NewsClassification;
use App\Enums\NewsStatus;
use Database\Factories\TeamNewsItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TeamNewsItem extends Model
{
    /** @use HasFactory<TeamNewsItemFactory> */
    use HasFactory;

    protected $fillable = [
        'source_id',
        'constructor_id',
        'external_url',
        'external_guid',
        'slug',
        'title_original',
        'title_bg',
        'summary_bg',
        'content_snippet',
        'published_at',
        'classification',
        'importance_score',
        'status',
    ];

    protected static function booted(): void
    {
        // Авто-генериран уникален slug за собствените article страници (/news/{slug}).
        static::saving(function (TeamNewsItem $item): void {
            if (blank($item->slug)) {
                $item->slug = static::uniqueSlug(
                    $item->title_bg ?: $item->title_original ?: 'novina',
                    $item->id,
                );
            }
        });
    }

    public static function uniqueSlug(string $title, ?int $excludeId = null): string
    {
        $base = Str::slug($title) ?: 'novina';
        $slug = $base;
        $n = 2;

        while (static::query()
            ->where('slug', $slug)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->exists()
        ) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }

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
