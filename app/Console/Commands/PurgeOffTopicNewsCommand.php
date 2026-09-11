<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\NewsStatus;
use App\Models\TeamNewsItem;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Еднократно чистене на вече публикувани новини извън темата.
 *
 * От тук нататък LLM гейтът (is_f1_related в NewsClassifier) ги спира преди
 * публикация. Тази команда наваксва архива, натрупан преди гейта.
 *
 * Работи по ключови думи, а не с LLM: повторна класификация на целия архив би
 * струвала пари за резултат, който няколко еднозначни думи дават сигурно.
 * Затова и прагът е висок — по-добре да пропуснем нещо, отколкото да скрием
 * легитимна Ф1 новина.
 */
class PurgeOffTopicNewsCommand extends Command
{
    protected $signature = 'news:purge-off-topic
        {--dry-run : Показва какво би било скрито, без да пипа базата}
        {--limit=0 : Ограничава броя обработени (0 = без ограничение)}';

    protected $description = 'Скрива вече публикувани новини, които не са за Формула 1 (MotoGP, NASCAR, рали и др.).';

    /**
     * Еднозначни маркери за други серии. Всички са с граници на думата, за да
     * не лови „Moto“ вътре в Motorsport.com или „WEC“ в средата на дума.
     *
     * @var array<int, string>
     */
    private const OFF_TOPIC_TERMS = [
        // Мотоциклетизъм
        'motogp', 'moto gp', 'moto2', 'moto3', 'superbike', 'wsbk',
        'мотоджипи', 'моторно гп',
        // Американски серии
        'nascar', 'indycar', 'indy 500', 'наскар', 'индикар',
        // Издръжливост и електрически серии
        'le mans', 'wec ', 'imsa', 'formula e', 'формула е', 'льо ман',
        // Рали и офроуд
        'wrc', 'dakar', 'rallycross', 'дакар', 'раликрос',
        // Други
        'dtm', 'nhra', 'motocross', 'мотокрос',
    ];

    /**
     * Думи, които връщат новината обратно в темата дори при съвпадение по-горе
     * („Хамилтън тества MotoGP мотор“ е Ф1 новина).
     *
     * @var array<int, string>
     */
    private const F1_TERMS = [
        'formula 1', 'formula one', 'f1 ', ' f1', 'grand prix',
        'формула 1', 'ф1', 'гран при', 'падок',
    ];

    public function handle(): int
    {
        $query = TeamNewsItem::query()
            ->inMainFeed()
            ->where(function (Builder $builder): void {
                foreach (self::OFF_TOPIC_TERMS as $term) {
                    $builder->orWhere('title_original', 'like', "%{$term}%")
                        ->orWhere('title_bg', 'like', "%{$term}%");
                }
            });

        if ($limit = (int) $this->option('limit')) {
            $query->limit($limit);
        }

        $candidates = $query->get(['id', 'title_bg', 'title_original', 'summary_bg']);

        $offTopic = $candidates->reject(fn (TeamNewsItem $item) => $this->mentionsF1($item));

        if ($offTopic->isEmpty()) {
            $this->info('Няма публикувани новини извън темата.');

            return self::SUCCESS;
        }

        foreach ($offTopic as $item) {
            $this->line("#{$item->id} — ".($item->title_bg ?? $item->title_original));
        }

        if ($this->option('dry-run')) {
            $this->warn("[dry-run] {$offTopic->count()} новини биха били скрити.");

            return self::SUCCESS;
        }

        // Rejected, а не изтриване: слугът остава зает и повторното дърпане на
        // същата емисия не я публикува пак.
        $hidden = TeamNewsItem::query()
            ->whereIn('id', $offTopic->pluck('id'))
            ->update(['status' => NewsStatus::Rejected->value]);

        $skipped = $candidates->count() - $offTopic->count();

        $this->info("Скрити {$hidden} новини извън темата.".($skipped > 0 ? " Запазени {$skipped} със споменаване на Ф1." : ''));

        return self::SUCCESS;
    }

    /**
     * Пази новини, които наистина говорят за Ф1, въпреки съвпадението.
     */
    private function mentionsF1(TeamNewsItem $item): bool
    {
        $haystack = mb_strtolower(implode(' ', array_filter([
            $item->title_bg,
            $item->title_original,
            $item->summary_bg,
        ])));

        foreach (self::F1_TERMS as $term) {
            if (str_contains($haystack, $term)) {
                return true;
            }
        }

        return false;
    }
}
