<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\WeeklyDigestMail;
use App\Models\Race;
use App\Models\Season;
use App\Models\User;
use App\Services\Predictions\LeaderboardService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class WeeklyDigestCommand extends Command
{
    protected $signature = 'f1:weekly-digest {--race= : ID на състезание (по подразбиране — последното изминало)}';

    protected $description = 'Изпраща неделен рекап на състезанието + класиране на prediction league-а.';

    public function handle(LeaderboardService $leaderboard): int
    {
        $season = Season::current();

        if ($season === null) {
            $this->warn('Няма текущ сезон.');

            return self::SUCCESS;
        }

        $race = $this->resolveRace($season);

        if ($race === null || ! $race->hasFinished()) {
            $this->warn('Няма завършено състезание за рекап.');

            return self::SUCCESS;
        }

        $recap = $this->buildRecap($race);
        $board = $leaderboard->forSeason($season)->take(10)->values()->all();

        $recipients = User::query()->whereNull('banned_at')->get();

        foreach ($recipients as $user) {
            $stats = $leaderboard->userStats($user, $season);

            Mail::to($user)->queue(new WeeklyDigestMail($race, $recap, $board, $stats));
        }

        $this->info("Дайджестът е поставен в опашката за {$recipients->count()} получатели.");

        return self::SUCCESS;
    }

    private function resolveRace(Season $season): ?Race
    {
        if ($id = $this->option('race')) {
            return Race::query()->find($id);
        }

        return $season->races()
            ->whereHas('results')
            ->whereNotNull('race_datetime_utc')
            ->orderByDesc('race_datetime_utc')
            ->first();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildRecap(Race $race): array
    {
        return $race->results()
            ->with('driver')
            ->whereNotNull('position')
            ->whereBetween('position', [1, 3])
            ->orderBy('position')
            ->get()
            ->map(fn ($result) => [
                'position' => $result->position,
                'driver' => $result->driver?->fullName(),
                'fastest_lap' => $result->fastest_lap,
            ])
            ->all();
    }
}
