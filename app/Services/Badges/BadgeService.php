<?php

declare(strict_types=1);

namespace App\Services\Badges;

use App\Models\Badge;
use App\Models\GameLapRecord;
use App\Models\Prediction;
use App\Models\Race;
use App\Models\Season;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Присъжда значки на потребителите според представянето им в прогнозите.
 * Идемпотентен — повторно присъждане на същата значка се игнорира.
 */
class BadgeService
{
    /**
     * Канонични дефиниции на значките (seed-ват се от BadgeSeeder).
     *
     * @var array<string, array{name:string, description:string, icon:string}>
     */
    public const DEFINITIONS = [
        'first-prediction' => [
            'name' => 'Дебют',
            'description' => 'Подаде първата си прогноза.',
            'icon' => 'heroicon-o-flag',
        ],
        'perfect-podium' => [
            'name' => 'Перфектен подиум',
            'description' => 'Позна точно тримата на подиума в едно състезание.',
            'icon' => 'heroicon-o-trophy',
        ],
        'high-scorer' => [
            'name' => 'Снайперист',
            'description' => 'Събра 60+ точки от едно състезание.',
            'icon' => 'heroicon-o-bolt',
        ],
        'pole-master' => [
            'name' => 'Господар на pole-а',
            'description' => 'Позна pole позицията в 5 състезания за сезона.',
            'icon' => 'heroicon-o-star',
        ],
        'season-champion' => [
            'name' => 'Шампион на сезона',
            'description' => 'Завърши №1 в класирането на прогнозите за сезона.',
            'icon' => 'heroicon-o-trophy',
        ],

        // ── Хронометърът ────────────────────────────────────────────────
        'game-first-lap' => [
            'name' => 'Първа обиколка',
            'description' => 'Записа първото си потвърдено време в Хронометъра.',
            'icon' => 'heroicon-o-clock',
        ],
        'game-beat-official' => [
            'name' => 'По-бърз от Падок',
            'description' => 'Изпревари официалния дух на Падок на някоя писта.',
            'icon' => 'heroicon-o-sparkles',
        ],
        'game-five-tracks' => [
            'name' => 'Пет писти',
            'description' => 'Потвърдени времена на пет различни писти.',
            'icon' => 'heroicon-o-map',
        ],
        'game-all-tracks' => [
            'name' => 'Клуб 24',
            'description' => 'Потвърдено време на всяка писта от календара.',
            'icon' => 'heroicon-o-globe-europe-africa',
        ],
        'game-track-record' => [
            'name' => 'Лилаво',
            'description' => 'Държа рекорда на цяла писта в Хронометъра.',
            'icon' => 'heroicon-o-bolt',
        ],
        'game-week-winner' => [
            'name' => 'Победител на седмицата',
            'description' => 'Спечели седмичното предизвикателство на пистата на уикенда.',
            'icon' => 'heroicon-o-trophy',
        ],
    ];

    private const HIGH_SCORE_THRESHOLD = 60;

    private const POLE_MASTER_THRESHOLD = 5;

    /**
     * Оценява значките за всички участници в дадено състезание (вика се след
     * точкуване). Връща броя новоприсъдени значки.
     */
    public function evaluateForRace(Race $race): int
    {
        $awarded = 0;
        $predictions = $race->predictions()->with('score')->get();

        foreach ($predictions as $prediction) {
            $user = $prediction->user;

            $awarded += $this->award($user, 'first-prediction');

            if ($this->isPerfectPodium($prediction)) {
                $awarded += $this->award($user, 'perfect-podium');
            }

            if (($prediction->score?->points ?? 0) >= self::HIGH_SCORE_THRESHOLD) {
                $awarded += $this->award($user, 'high-scorer');
            }

            if ($this->correctPoleCount($user, $race->season_id) >= self::POLE_MASTER_THRESHOLD) {
                $awarded += $this->award($user, 'pole-master');
            }
        }

        return $awarded;
    }

    /**
     * Присъжда значка "Шампион на сезона" на водача в класирането.
     */
    public function awardSeasonChampion(Season $season, User $champion): int
    {
        return $this->award($champion, 'season-champion');
    }

    /**
     * Значките от Хронометъра — вика се от ValidateGameLapJob СЛЕД успешна
     * валидация: отхвърлена от преиграването обиколка не бива да е раздала
     * значки, които няма как да се върнат.
     */
    public function evaluateForGameLap(GameLapRecord $record): int
    {
        $user = $record->user;

        if ($user === null) {
            return 0;
        }

        $awarded = $this->award($user, 'game-first-lap');

        // По-бърз от официалния дух на Падок за тази писта.
        $ghostPath = public_path("game-ghosts/{$record->track_slug}.json");
        if (file_exists($ghostPath)) {
            try {
                $ghost = json_decode((string) file_get_contents($ghostPath), true, 8, JSON_THROW_ON_ERROR);
                if (is_numeric($ghost['lapMs'] ?? null) && $record->lap_ms < (int) $ghost['lapMs']) {
                    $awarded += $this->award($user, 'game-beat-official');
                }
            } catch (\JsonException) {
                // Повреден файл на духа — значката просто изчаква.
            }
        }

        $tracksPlayed = GameLapRecord::query()
            ->counted()
            ->where('user_id', $user->id)
            ->distinct()
            ->count('track_slug');

        if ($tracksPlayed >= 5) {
            $awarded += $this->award($user, 'game-five-tracks');
        }
        if ($tracksPlayed >= count((array) config('game.tracks', []))) {
            $awarded += $this->award($user, 'game-all-tracks');
        }

        // Рекордът на пистата (лилаво): никой counted не е по-бърз.
        $overallBest = GameLapRecord::query()
            ->counted()
            ->where('track_slug', $record->track_slug)
            ->min('lap_ms');

        if ($overallBest !== null && (int) $overallBest === $record->lap_ms) {
            $awarded += $this->award($user, 'game-track-record');
        }

        return $awarded;
    }

    /** Победителят в седмичното предизвикателство (game:weekly-wrap). */
    public function awardWeekWinner(User $user): int
    {
        return $this->award($user, 'game-week-winner');
    }

    /**
     * Присъжда значка ако потребителят още я няма. Връща 1 при ново присъждане.
     */
    private function award(User $user, string $slug): int
    {
        $badge = Badge::query()->where('slug', $slug)->first();

        if ($badge === null || $user->badges()->where('badges.id', $badge->id)->exists()) {
            return 0;
        }

        $user->badges()->attach($badge->id, ['awarded_at' => Carbon::now()]);

        return 1;
    }

    private function isPerfectPodium(Prediction $prediction): bool
    {
        $breakdown = $prediction->score?->breakdown_json ?? [];
        $rules = config('predictions.scoring.exact');

        return ($breakdown['p1'] ?? 0) === $rules['p1']
            && ($breakdown['p2'] ?? 0) === $rules['p2']
            && ($breakdown['p3'] ?? 0) === $rules['p3'];
    }

    private function correctPoleCount(User $user, int $seasonId): int
    {
        return Prediction::query()
            ->where('predictions.user_id', $user->id)
            ->join('races', 'races.id', '=', 'predictions.race_id')
            ->where('races.season_id', $seasonId)
            ->whereColumn('predictions.pole_driver_id', 'races.pole_driver_id')
            ->whereNotNull('races.pole_driver_id')
            ->count();
    }
}
