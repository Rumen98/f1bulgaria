<?php

declare(strict_types=1);

namespace App\Services\Badges;

use App\Models\Badge;
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
            'description' => 'Събра 45+ точки от едно състезание.',
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
    ];

    /**
     * Прагът е сверен с текущата схема (виж config/predictions.php):
     * максимумът е 78 т. (58 подиум + 20 бонуси), а без нито една точна
     * позиция таванът е 35. 45 иска поне една точна позиция + силни бонуси —
     * рядко, но постижимо. Старият праг 60 беше от схемата с максимум 98.
     */
    private const HIGH_SCORE_THRESHOLD = 45;

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
     * „Дебют“ веднага при първата прогноза — не чак при неделния синхрон.
     * Значката е обратна връзка за действието; три дни закъснение я обезсмисля.
     */
    public function awardDebut(User $user): int
    {
        return $this->award($user, 'first-prediction');
    }

    /**
     * Присъжда значка "Шампион на сезона" на водача в класирането.
     */
    public function awardSeasonChampion(Season $season, User $champion): int
    {
        return $this->award($champion, 'season-champion');
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
