<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\QuizQuestionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class QuizQuestion extends Model
{
    /** @use HasFactory<QuizQuestionFactory> */
    use HasFactory;

    /** @var array<int, string> */
    protected $fillable = [
        'code',
        'question',
        'option_1',
        'option_2',
        'option_3',
        'option_4',
        'correct_option',
        'is_active',
    ];

    /**
     * Defense-in-depth: верният отговор никога не бива да изтича към клиента
     * при случайно сериализиране на целия модел (Inertia/JSON).
     *
     * @var list<string>
     */
    protected $hidden = [
        'correct_option',
    ];

    protected static function booted(): void
    {
        // Авто-генериран код при ръчно създаване (Filament) — seeder-ът винаги подава свой.
        static::creating(function (QuizQuestion $question): void {
            if (blank($question->code)) {
                $question->code = static::uniqueCode((string) $question->question);
            }
        });
    }

    public static function uniqueCode(string $question): string
    {
        $base = Str::limit(Str::slug($question), 48, '') ?: 'vapros';
        $code = $base;
        $n = 2;

        while (static::query()->where('code', $code)->exists()) {
            $code = "{$base}-{$n}";
            $n++;
        }

        return $code;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'correct_option' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<QuizQuestion>  $query
     * @return Builder<QuizQuestion>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Четирите опции като 0-базиран масив (позиция N => option_{N+1}).
     * НЕ съдържа верния отговор.
     *
     * @return array<int, string>
     */
    public function optionsList(): array
    {
        return [$this->option_1, $this->option_2, $this->option_3, $this->option_4];
    }
}
