<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RaceDataRecapFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Готовият рекап с данни за едно състезание — числата, текстът и сериите за
 * графиките. Виж миграцията за това защо тук няма сурова телеметрия.
 */
class RaceDataRecap extends Model
{
    /** @use HasFactory<RaceDataRecapFactory> */
    use HasFactory;

    /** @var array<int, string> */
    protected $fillable = [
        'race_id',
        'openf1_session_key',
        'openf1_quali_session_key',
        'openf1_circuit_key',
        'facts',
        'charts',
        'headline',
        'body_bg',
        'news_item_id',
        'attempts',
        'last_error',
        'generated_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'facts' => 'array',
            'charts' => 'array',
            'openf1_session_key' => 'integer',
            'openf1_quali_session_key' => 'integer',
            'openf1_circuit_key' => 'integer',
            'attempts' => 'integer',
            'generated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Race, $this> */
    public function race(): BelongsTo
    {
        return $this->belongsTo(Race::class);
    }

    /** @return BelongsTo<TeamNewsItem, $this> */
    public function newsItem(): BelongsTo
    {
        return $this->belongsTo(TeamNewsItem::class, 'news_item_id');
    }

    /**
     * Рекап, който наистина има какво да покаже. Ред без generated_at е опит,
     * който е паднал по средата — не бива да се появява в списъци.
     *
     * @param  Builder<RaceDataRecap>  $query
     * @return Builder<RaceDataRecap>
     */
    public function scopeReady(Builder $query): Builder
    {
        return $query->whereNotNull('generated_at')->whereNotNull('facts');
    }
}
