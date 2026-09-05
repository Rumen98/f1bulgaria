<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NewsClassification;
use App\Enums\NewsStatus;
use Database\Factories\TeamNewsItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TeamNewsItem extends Model
{
    /** @use HasFactory<TeamNewsItemFactory> */
    use HasFactory;

    protected $fillable = [
        'source_id',
        'constructor_id',
        'is_tsolov',
        'is_f1_related',
        'external_url',
        'external_guid',
        'slug',
        'title_original',
        'title_bg',
        'summary_bg',
        'full_article_bg',
        'our_analysis_bg',
        'key_facts',
        'featured_image',
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
            'is_tsolov' => 'boolean',
            'is_f1_related' => 'boolean',
            'key_facts' => 'array',
            'featured_image' => 'array',
        ];
    }

    /**
     * Всичко, което е минало модерацията — независимо за коя серия е.
     *
     * Ползва се там, където обхватът е „всяка наша статия": sitemap,
     * търсачка, дописване на пълни статии. Статиите за Цолов от Ф2 имат
     * собствени страници и заслужават и индексация, и да се намират.
     *
     * @param  Builder<self>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->whereIn('status', collect(NewsStatus::publiclyVisible())->map(fn (NewsStatus $s) => $s->value));
    }

    /**
     * Главната емисия на сайта — само Формула 1.
     *
     * Това е ПОДРАЗБИРАЩИЯТ СЕ обхват за всяка публична повърхност (списък,
     * начало, RSS, дайджест, канал, отборни страници). Изключването на не-Ф1
     * е тук, а не в единайсетте заявки, които показват новини: забравено
     * място тогава значи „не показва Ф2", а не обратното.
     *
     * Новина за Цолов, която Е и Ф1 новина (тест с болид на Ф1 отбор),
     * минава оттук нормално — тя не е Ф2 съдържание.
     *
     * @param  Builder<self>  $query
     */
    public function scopeInMainFeed(Builder $query): void
    {
        $query->published()->where('is_f1_related', true);
    }

    /**
     * Кътът на Никола Цолов — пълният му архив, включително Ф2.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAboutTsolov(Builder $query): void
    {
        $query->published()->where('is_tsolov', true);
    }

    /** @return BelongsTo<TeamNewsSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(TeamNewsSource::class, 'source_id');
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /** @return BelongsTo<Constructor, $this> */
    public function constructor(): BelongsTo
    {
        return $this->belongsTo(Constructor::class);
    }
}
