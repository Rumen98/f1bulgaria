<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ChannelPostKind;
use App\Models\Race;
use App\Models\User;
use App\Services\Badges\BadgeService;
use App\Services\Game\LeaderboardService;
use App\Services\Game\WeekTrackResolver;
use App\Services\Telegram\ChannelQueue;
use App\Services\Telegram\Formatters\GameChallengeFormatter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Седмичното предизвикателство на Хронометъра в Telegram канала:
 *
 *   --mode=open  (четвъртък) „Пистата на седмицата: … — карай!"
 *   --mode=wrap  (понеделник) резултати: топ 3 + значка + дуел с победителя
 *
 * Каналният конвейер (ChannelQueue → publisher) е идемпотентен по
 * (kind, subject) — състезанието е subject-ът, така че повторно пускане не
 * дублира постове.
 */
class GameWeeklyChannelCommand extends Command
{
    protected $signature = 'game:weekly-channel
        {--mode=open : open (старт, четвъртък) или wrap (резултати, понеделник)}';

    protected $description = 'Поставя в каналната опашка поста на седмичното предизвикателство.';

    public function __construct(
        private readonly WeekTrackResolver $weekTrack,
        private readonly LeaderboardService $leaderboard,
        private readonly ChannelQueue $queue,
        private readonly GameChallengeFormatter $formatter,
        private readonly BadgeService $badges,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! config('features.game')) {
            $this->line('Играта е изключена (features.game) — нищо за постване.');

            return self::SUCCESS;
        }

        $week = $this->weekTrack->resolve();

        if ($week === null) {
            $this->line('Няма писта на уикенда в календара — пропускам.');

            return self::SUCCESS;
        }

        $race = Race::query()->find($week['race_id']);

        if ($race === null) {
            $this->warn('Състезанието на седмицата липсва в базата.');

            return self::FAILURE;
        }

        $trackName = collect($this->leaderboard->trackIndex())
            ->firstWhere('slug', $week['slug'])['name'] ?? $week['slug'];
        $gameUrl = route('game');

        if ($this->option('mode') === 'wrap') {
            return $this->wrap($race, $week, (string) $trackName, $gameUrl);
        }

        $body = $this->formatter->challenge((string) $trackName, $gameUrl.'?track='.$week['slug']);
        $outcome = $this->queue->enqueue($race, ChannelPostKind::GameChallenge, $body);
        $this->info("Стартов пост: {$outcome->value}.");

        return self::SUCCESS;
    }

    /**
     * @param  array{slug: string, race_id: int, week_start: Carbon}  $week
     */
    private function wrap(Race $race, array $week, string $trackName, string $gameUrl): int
    {
        $top = $this->leaderboard->topLaps($week['slug'], null, 10, $week['week_start']);

        if ($top->isEmpty()) {
            $this->line('Никой не е карал тази седмица — без резултатен пост.');

            return self::SUCCESS;
        }

        // Значката на победителя (идемпотентно).
        $winner = User::query()->find($top->first()['user_id']);
        if ($winner !== null) {
            $this->badges->awardWeekWinner($winner);
        }

        $body = $this->formatter->results($trackName, $week['slug'], $top, $gameUrl);
        $outcome = $this->queue->enqueue($race, ChannelPostKind::GameResults, $body);
        $this->info("Резултатен пост: {$outcome->value}.");

        return self::SUCCESS;
    }
}
