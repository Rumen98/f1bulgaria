<?php

declare(strict_types=1);

namespace App\Services\Game;

/**
 * Прави сурови DEM височини годни за каране.
 *
 * Суровите данни не стават директно по три причини:
 *
 *  1. Резолюцията е 25–30 m, а трасето се сампълва на 20–45 m. Съседни точки
 *     често падат в един и същи пиксел и профилът излиза стъпаловиден.
 *  2. DEM-ът описва ТЕРЕНА, не пътя. Там, където трасето минава през тунел
 *     или изкоп, се връща височината на скалата отгоре.
 *  3. Има дупки в покритието (null).
 *
 * Затова: запълване на дупките → медианен филтър срещу единични скокове →
 * изглаждане → ограничаване на наклона до физически възможен.
 */
class ElevationProfile
{
    /** Прозорец на медианния филтър (в точки, нечетно число). */
    private const MEDIAN_WINDOW = 5;

    /**
     * @param  array<int, float|null>  $elevations  Височини в метри, циклични
     * @param  array<int, float>  $segmentLengths  Разстояние от i до i+1, метри
     * @return array{profile: array<int, float>, stats: array<string, float|int>}
     */
    public function clean(
        array $elevations,
        array $segmentLengths,
        float $maxSlope,
        int $passes,
    ): array {
        $filled = $this->fillGaps($elevations);
        $gaps = count(array_filter($elevations, fn (?float $v): bool => $v === null));

        $rawRange = max($filled) - min($filled);
        $rawMaxSlope = $this->steepest($filled, $segmentLengths);

        $profile = $this->medianFilter($filled);

        for ($i = 0; $i < $passes; $i++) {
            $profile = $this->smooth($profile);
        }

        // Грубо ограничаване още тук — маха най-дивите артефакти, преди
        // изглаждането да ги размаже в съседните точки. Окончателният таван
        // се налага върху гъстата мрежа (limitSlope), защото сгъстяването
        // може само да вдигне наклона, не да го свали.
        $profile = $this->clampSlope($profile, $segmentLengths, $maxSlope);

        // Нулевата точка е най-ниската част на трасето; така колата кара около
        // y ≈ 0 вместо на 400 m над сцената.
        $floor = min($profile);
        $profile = array_map(fn (float $v): float => round($v - $floor, 2), $profile);

        return [
            'profile' => $profile,
            'stats' => [
                'gaps' => $gaps,
                'raw_range' => round($rawRange, 1),
                'final_range' => round(max($profile) - min($profile), 1),
                'raw_max_slope' => round($rawMaxSlope, 3),
                'final_max_slope' => round($this->steepest($profile, $segmentLengths), 3),
            ],
        ];
    }

    /**
     * Налага таван на наклона върху вече сгъстения, равномерен профил.
     *
     * Вика се СЛЕД интерполацията към работната мрежа: сгъстяването с
     * smoothstep има производна 1.5× по-стръмна от линейната в средата на
     * сегмента, така че наклон, ограничен преди това, излиза отвъд тавана.
     *
     * @param  array<int, float>  $profile
     * @return array<int, float>
     */
    public function limitSlope(array $profile, float $spacing, float $maxSlope): array
    {
        $segments = array_fill(0, count($profile), $spacing);
        $limited = $this->clampSlope($profile, $segments, $maxSlope);

        // Ограничаването може да е изместило цялото трасе нагоре.
        $floor = min($limited);

        return array_map(fn (float $v): float => round($v - $floor, 3), $limited);
    }

    /**
     * Най-стръмният наклон в профил с равномерна стъпка (m/m).
     *
     * @param  array<int, float>  $profile
     */
    public function steepestUniform(array $profile, float $spacing): float
    {
        return $this->steepest($profile, array_fill(0, count($profile), $spacing));
    }

    /**
     * Запълва липсващите стойности с линейна интерполация между известните.
     *
     * @param  array<int, float|null>  $elevations
     * @return array<int, float>
     */
    private function fillGaps(array $elevations): array
    {
        $count = count($elevations);
        $known = [];

        foreach ($elevations as $i => $value) {
            if ($value !== null) {
                $known[] = $i;
            }
        }

        if ($known === []) {
            return array_fill(0, $count, 0.0);
        }

        $out = [];

        foreach ($elevations as $i => $value) {
            if ($value !== null) {
                $out[$i] = (float) $value;

                continue;
            }

            // Най-близките известни съседи в двете посоки, с wrap.
            $before = null;
            $after = null;

            for ($step = 1; $step <= $count; $step++) {
                $b = ($i - $step + $count) % $count;
                if ($before === null && $elevations[$b] !== null) {
                    $before = ['index' => $step, 'value' => (float) $elevations[$b]];
                }

                $a = ($i + $step) % $count;
                if ($after === null && $elevations[$a] !== null) {
                    $after = ['index' => $step, 'value' => (float) $elevations[$a]];
                }

                if ($before !== null && $after !== null) {
                    break;
                }
            }

            $total = $before['index'] + $after['index'];
            $out[$i] = $before['value']
                + ($after['value'] - $before['value']) * ($before['index'] / $total);
        }

        ksort($out);

        return array_values($out);
    }

    /**
     * Медиана в плъзгащ прозорец — маха единични скокове, без да размазва
     * истинските наклони, както би направило осредняването.
     *
     * @param  array<int, float>  $values
     * @return array<int, float>
     */
    private function medianFilter(array $values): array
    {
        $count = count($values);
        $half = (int) floor(self::MEDIAN_WINDOW / 2);
        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $window = [];

            for ($k = -$half; $k <= $half; $k++) {
                $window[] = $values[($i + $k + $count) % $count];
            }

            sort($window);
            $out[] = $window[$half];
        }

        return $out;
    }

    /**
     * Претеглено осредняване по три съседни точки (ядро 1-2-1).
     *
     * @param  array<int, float>  $values
     * @return array<int, float>
     */
    private function smooth(array $values): array
    {
        $count = count($values);
        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $out[] = (
                $values[($i - 1 + $count) % $count]
                + 2 * $values[$i]
                + $values[($i + 1) % $count]
            ) / 4;
        }

        return $out;
    }

    /**
     * Итеративно сваля наклоните под тавана.
     *
     * Излишъкът се разделя между двата края на сегмента, така че средното ниво
     * не се измества. Няколко минавания разпръскват корекцията по-нашироко.
     *
     * @param  array<int, float>  $values
     * @param  array<int, float>  $segmentLengths
     * @return array<int, float>
     */
    private function clampSlope(array $values, array $segmentLengths, float $maxSlope): array
    {
        $count = count($values);

        for ($pass = 0; $pass < 40; $pass++) {
            $violations = 0;

            for ($i = 0; $i < $count; $i++) {
                $j = ($i + 1) % $count;
                $length = max($segmentLengths[$i] ?? 1.0, 0.1);
                $limit = $maxSlope * $length;
                $diff = $values[$j] - $values[$i];

                if (abs($diff) <= $limit) {
                    continue;
                }

                $violations++;
                $excess = (abs($diff) - $limit) / 2;
                $sign = $diff > 0 ? 1 : -1;

                $values[$i] += $sign * $excess;
                $values[$j] -= $sign * $excess;
            }

            if ($violations === 0) {
                break;
            }
        }

        return $values;
    }

    /**
     * Най-стръмният наклон в профила (m/m).
     *
     * @param  array<int, float>  $values
     * @param  array<int, float>  $segmentLengths
     */
    private function steepest(array $values, array $segmentLengths): float
    {
        $count = count($values);
        $worst = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $j = ($i + 1) % $count;
            $length = max($segmentLengths[$i] ?? 1.0, 0.1);
            $worst = max($worst, abs($values[$j] - $values[$i]) / $length);
        }

        return $worst;
    }
}
