<?php

declare(strict_types=1);

namespace App\Filament\Resources\GamePlayers\Widgets;

use App\Models\GameSession;
use App\Models\GameVisit;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Кой влиза в играта — и гостите. Отварянията и гост-стартовете идват от
 * game_visits (дневен хеш, без бисквитка), стартовете на регистрираните — от
 * game_sessions. „Уникални" = уникални за деня, сумирани по дни; същият гост
 * в два различни дни е двама, защото не го проследяваме между дните.
 */
class GameVisitsOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Посещения на играта';

    protected ?string $description = 'Днес · 7 дни · 30 дни (софийско време). Гостите се броят по дневен хеш — уникални за деня, без бисквитка; гост, който после влезе в акаунта си, се брои и в двете. Опит = „Карай", рестарт или „Нова обиколка". Твоите отваряния като админ не се броят.';

    protected function getStats(): array
    {
        $now = CarbonImmutable::now('Europe/Sofia');
        $windows = [
            'Днес' => $now->startOfDay(),
            '7 дни' => $now->subDays(6)->startOfDay(),
            '30 дни' => $now->subDays(29)->startOfDay(),
        ];

        $stats = [];
        foreach ($windows as $label => $from) {
            $stats[] = $this->windowStat($label, $from->utc());
        }

        return $stats;
    }

    private function windowStat(string $label, CarbonImmutable $from): Stat
    {
        $views = GameVisit::query()->where('kind', GameVisit::KIND_VIEW)->where('created_at', '>=', $from);

        $guestVisitors = (int) (clone $views)->whereNull('user_id')->distinct()->count('visitor_key');
        $userVisitors = (int) (clone $views)->whereNotNull('user_id')->distinct()->count('user_id');
        $openings = (int) (clone $views)->count();
        $mobileShare = $openings > 0
            ? round((clone $views)->where('device', 'mobile')->count() / $openings * 100)
            : 0;

        $guestStarts = (int) GameVisit::query()->where('kind', GameVisit::KIND_START)->where('created_at', '>=', $from)->count();
        $userStarts = (int) GameSession::query()->where('created_at', '>=', $from)->count();
        $userStarters = (int) GameSession::query()->where('created_at', '>=', $from)->distinct()->count('user_id');

        return Stat::make($label.': посетители', ($guestVisitors + $userVisitors).' ('.$guestVisitors.' гости · '.$userVisitors.' регистрирани)')
            ->description("{$openings} отваряния, {$mobileShare}% от телефон · опити: {$guestStarts} гости · {$userStarts} регистрирани ({$userStarters} души)")
            ->color($guestVisitors + $userVisitors > 0 ? 'success' : 'gray');
    }
}
