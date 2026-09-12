<?php

declare(strict_types=1);

namespace App\Filament\Resources\GameSessions\Widgets;

use App\Filament\Resources\GameSessions\GameSessionPresentation;
use App\Filament\Resources\GameSessions\Pages\ListGameSessions;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class GameSessionsOverview extends StatsOverviewWidget
{
    use InteractsWithPageTable;

    /** Агрегатите са тежки; без polling — презареждането е нарочно. */
    protected ?string $pollingInterval = null;

    protected function getTablePage(): string
    {
        return ListGameSessions::class;
    }

    protected function getStats(): array
    {
        $query = $this->getPageTableQuery()->reorder();
        $counts = (clone $query)->toBase()->select([])->selectRaw("COUNT(*) AS total,
            COUNT(DISTINCT user_id) AS players,
            SUM(CASE WHEN status = 'legacy' THEN 1 ELSE 0 END) AS legacy,
            SUM(CASE WHEN status != 'legacy' THEN 1 ELSE 0 END) AS tracked,
            SUM(CASE WHEN lap_count > 0 THEN 1 ELSE 0 END) AS lap_finishers,
            SUM(CASE WHEN mode = 'race' AND status = 'completed' THEN 1 ELSE 0 END) AS race_finishers,
            SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) AS errors,
            SUM(CASE WHEN mode = 'solo' AND status != 'legacy' THEN 1 ELSE 0 END) AS solo_starts,
            SUM(CASE WHEN mode = 'solo' AND lap_count > 0 THEN 1 ELSE 0 END) AS solo_finishers,
            SUM(CASE WHEN mode = 'race' AND status != 'legacy' THEN 1 ELSE 0 END) AS race_starts,
            SUM(CASE WHEN mode = 'race' AND lap_count > 0 THEN 1 ELSE 0 END) AS race_lap_finishers,
            SUM(CASE WHEN device = 'mobile' AND status != 'legacy' THEN 1 ELSE 0 END) AS mobile_starts,
            SUM(CASE WHEN device = 'mobile' AND lap_count > 0 THEN 1 ELSE 0 END) AS mobile_finishers,
            SUM(CASE WHEN device = 'desktop' AND status != 'legacy' THEN 1 ELSE 0 END) AS desktop_starts,
            SUM(CASE WHEN device = 'desktop' AND lap_count > 0 THEN 1 ELSE 0 END) AS desktop_finishers,
            SUM(lap_count) AS laps,
            SUM(valid_lap_count) AS valid_laps,
            SUM(invalid_lap_count) AS invalid_laps,
            AVG(CASE WHEN status != 'legacy' THEN active_ms END) AS average_ms")->first();
        $moved = (clone $query)->whereHas('events', fn (Builder $events): Builder => $events->where('type', 'moving'))->count();
        $unknown = (clone $query)->whereIn('status', ['active', 'paused'])->where(fn (Builder $q): Builder => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subMinutes(2)))->count();
        $tracked = (int) $counts->tracked;
        $finishers = (int) $counts->lap_finishers;
        $percentage = $tracked > 0 ? round($finishers / $tracked * 100).'% от подробните карания' : 'Все още няма подробни карания';

        return [
            Stat::make('Опити / играчи', ((int) $counts->total).' / '.((int) $counts->players))->description($tracked.' с подробна история · '.((int) $counts->legacy).' само старт; опит = „Карай" или рестарт'),
            Stat::make('Потеглили карания', $moved.' / '.$tracked)->description('Движение от поне 3 км/ч / подробни карания'),
            Stat::make('Карания с поне 1 финал', $finishers)->description($percentage)->color('success'),
            Stat::make('Завършени обиколки', (int) $counts->laps)->description(((int) $counts->valid_laps).' чисти · '.((int) $counts->invalid_laps).' невалидни'),
            Stat::make('Завършени състезания', ((int) $counts->race_finishers).' / '.((int) $counts->race_starts))->description('Финал на състезанието / подробни стартове в режима'),
            Stat::make('Средно активно време', GameSessionPresentation::duration($counts->average_ms === null ? null : (int) $counts->average_ms))->description('Само подробни карания; включва текущите'),
            Stat::make('Без скорошни данни', $unknown)->description('Няма краен сигнал; причината е неизвестна')->color('warning'),
            Stat::make('Технически грешки', (int) $counts->errors)->description('Карания с получен сигнал за грешка')->color('danger'),
            Stat::make('Соло: с финал / стартове', ((int) $counts->solo_finishers).' / '.((int) $counts->solo_starts))->description('Поне една обиколка / подробни соло карания'),
            Stat::make('Състезание: с обиколка / стартове', ((int) $counts->race_lap_finishers).' / '.((int) $counts->race_starts))->description('Поне една обиколка; не непременно цялото състезание'),
            Stat::make('Телефон: с финал / стартове', ((int) $counts->mobile_finishers).' / '.((int) $counts->mobile_starts))->description('Поне една обиколка / подробни карания на телефон'),
            Stat::make('Компютър: с финал / стартове', ((int) $counts->desktop_finishers).' / '.((int) $counts->desktop_starts))->description('Поне една обиколка / подробни карания на компютър'),
        ];
    }
}
