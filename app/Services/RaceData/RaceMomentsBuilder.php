<?php

declare(strict_types=1);

namespace App\Services\RaceData;

use App\Models\Race;

/**
 * Моментите, в които разликата между първите двама се е менила най-много.
 *
 * Тук няма LLM и няма да има. Числовият пазач в RecapComposer пропуска всяко
 * цяло число до 30, тоест изречение като „на обиколка 27 питстопът обърна
 * състезанието“ би минало през него без съпротива. Затова етикетите се
 * сглобяват от шаблони и казват САМО какво се е случило и с колко: в данните
 * няма контрафактуал, с който да се провери твърдение за причина.
 *
 * Разликата се смята от кумулативните времена, а не от `intervals` — те носят
 * времеви щампи, не номера на обиколки, и биха дали друга ос от останалите
 * серии в страницата.
 */
class RaceMomentsBuilder
{
    /** Междинните сегменти; със старта и финала изходът става 3 до 6 момента. */
    private const MAX_SEGMENTS = 4;

    public function __construct(private readonly DriverResolver $drivers) {}

    /**
     * @return array<int, array{from:int, to:int, type:string, label:string, detail:string, drivers:array<int, int>, delta:?float}>
     */
    public function build(Race $race, RaceDataBundle $bundle): array
    {
        $pair = $this->leadingPair($bundle);

        if ($pair === null) {
            return [];
        }

        [$first, $second] = $pair;
        $gaps = $this->gapsPerLap($bundle->cumulativeTimes(), $first, $second);

        if (count($gaps) < 2) {
            return [];
        }

        $people = $this->drivers->map($race, $bundle->drivers);
        $names = [
            $first => $people[$first]['name'] ?? ('#'.$first),
            $second => $people[$second]['name'] ?? ('#'.$second),
        ];

        $segments = $this->balanced($bundle, $this->strongestSegments($gaps), $names, $first, $second);

        // Само старт и финал не са история, а два надписа — а контрактът иска
        // поне три момента. По-честно е разделът да липсва.
        if ($segments === []) {
            return [];
        }

        $moments = [$this->startMoment($bundle, $gaps, $names, $first, $second)];

        foreach ($segments as $segment) {
            $moments[] = $this->segmentMoment($bundle, $segment, $names, $first, $second);
        }

        $moments[] = $this->finishMoment($gaps, $names, $first, $second);

        return $moments;
    }

    /**
     * Първите двама от реда на финиширане.
     *
     * @return array{0:int, 1:int}|null
     */
    private function leadingPair(RaceDataBundle $bundle): ?array
    {
        $order = $bundle->result
            ->filter(fn (array $r) => (int) ($r['position'] ?? 0) > 0)
            ->sortBy(fn (array $r) => (int) $r['position'])
            ->map(fn (array $r) => (int) $r['driver_number'])
            ->values();

        if ($order->count() < 2) {
            return null;
        }

        return [(int) $order->get(0), (int) $order->get(1)];
    }

    /**
     * g(L) = кумулативното време на втория минус това на първия. Положително
     * значи, че вторият е назад — обичайното състояние.
     *
     * @param  array<int, array<int, float>>  $cumulative
     * @return array<int, float> [обиколка => разлика в секунди]
     */
    private function gapsPerLap(array $cumulative, int $first, int $second): array
    {
        $gaps = [];

        foreach ($cumulative[$first] ?? [] as $lap => $time) {
            if (isset($cumulative[$second][$lap])) {
                $gaps[$lap] = round($cumulative[$second][$lap] - $time, 3);
            }
        }

        ksort($gaps);

        return $gaps;
    }

    /**
     * Сегментите с еднакъв знак на промяната, подредени по обиколка, но
     * подбрани по сила. Обиколките без промяна затварят сегмента и отпадат —
     * нула не е посока.
     *
     * @param  array<int, float>  $gaps
     * @return array<int, array{sign:int, from:int, to:int, delta:float}>
     */
    private function strongestSegments(array $gaps): array
    {
        $laps = array_keys($gaps);
        $segments = [];
        $current = null;

        for ($i = 1; $i < count($laps); $i++) {
            $lap = $laps[$i];
            $delta = round($gaps[$lap] - $gaps[$laps[$i - 1]], 3);
            $sign = $delta <=> 0.0;

            if ($current !== null && $current['sign'] === $sign) {
                $current['to'] = $lap;
                $current['delta'] = round($current['delta'] + $delta, 3);

                continue;
            }

            if ($current !== null && $current['sign'] !== 0) {
                $segments[] = $current;
            }

            $current = ['sign' => $sign, 'from' => $lap, 'to' => $lap, 'delta' => $delta];
        }

        if ($current !== null && $current['sign'] !== 0) {
            $segments[] = $current;
        }

        usort($segments, fn (array $a, array $b) => abs($b['delta']) <=> abs($a['delta']));

        return $segments;
    }

    /**
     * Подбор с квота по вид, а не просто най-силните.
     *
     * Питстопът мести разликата с двайсетина секунди, неутрализацията —
     * с толкова; истинско предимство в темпото е половин секунда на обиколка.
     * Класиране само по големина затова връща почти винаги пит цикли и
     * коли за сигурност, тоест разделът отговаря „механиката“ на въпроса къде
     * се е решавало. И по-лошо: пит цикълът е две съседни ниши в обратни
     * посоки, които се компенсират — заедно не са решили нищо.
     *
     * Затова половината места са запазени за темпо, ако изобщо има такива
     * сегменти. Ако няма, механичните ги допълват — и обратното.
     *
     * @param  array<int, array{sign:int, from:int, to:int, delta:float}>  $segments
     * @param  array<int, string>  $names
     * @return array<int, array{sign:int, from:int, to:int, delta:float}>
     */
    private function balanced(RaceDataBundle $bundle, array $segments, array $names, int $first, int $second): array
    {
        $pace = [];
        $mechanical = [];

        foreach ($segments as $segment) {
            if ($this->isMechanical($bundle, $segment, $names, $first, $second)) {
                $mechanical[] = $segment;
            } else {
                $pace[] = $segment;
            }
        }

        $quota = intdiv(self::MAX_SEGMENTS, 2);
        $picked = array_slice($pace, 0, $quota);
        $picked = array_merge($picked, array_slice($mechanical, 0, self::MAX_SEGMENTS - count($picked)));

        // Недостигът от едната страна се покрива от другата — таванът е важен,
        // съотношението е предпочитание.
        if (count($picked) < self::MAX_SEGMENTS) {
            $picked = array_merge($picked, array_slice($pace, $quota, self::MAX_SEGMENTS - count($picked)));
        }

        usort($picked, fn (array $a, array $b) => $a['from'] <=> $b['from']);

        return $picked;
    }

    /**
     * Механичен ли е сегментът — движи ли го пит лейнът или неутрализацията,
     * вместо темпото на пистата.
     *
     * @param  array{sign:int, from:int, to:int, delta:float}  $segment
     * @param  array<int, string>  $names
     */
    private function isMechanical(RaceDataBundle $bundle, array $segment, array $names, int $first, int $second): bool
    {
        return $this->stopsInside($bundle, $segment, $names, $first, $second) !== []
            || $this->underNeutralisation($bundle, $segment);
    }

    /**
     * @param  array<int, float>  $gaps
     * @param  array<int, string>  $names
     * @return array{from:int, to:int, type:string, label:string, detail:string, drivers:array<int, int>, delta:null}
     */
    private function startMoment(RaceDataBundle $bundle, array $gaps, array $names, int $first, int $second): array
    {
        $lap = (int) array_key_first($gaps);
        $grid = $bundle->grid->mapWithKeys(
            fn (array $g) => [(int) $g['driver_number'] => (int) ($g['position'] ?? 0)],
        );

        $from = (int) $grid->get($first, 0);
        $to = (int) $grid->get($second, 0);

        $detail = $from > 0 && $to > 0
            ? sprintf('%s тръгва %d-и, %s — %d-и.', $names[$first], $from, $names[$second], $to)
            : sprintf('След обиколка %d разликата е %s с.', $lap, $this->decimal(abs($gaps[$lap])));

        return [
            'from' => $lap,
            'to' => $lap,
            'type' => 'start',
            'label' => 'Старт',
            'detail' => $detail,
            'drivers' => [$first, $second],
            // Стартът е състояние, а полето значи ПРОМЯНА в разликата.
            'delta' => null,
        ];
    }

    /**
     * @param  array<int, float>  $gaps
     * @param  array<int, string>  $names
     * @return array{from:int, to:int, type:string, label:string, detail:string, drivers:array<int, int>, delta:null}
     */
    private function finishMoment(array $gaps, array $names, int $first, int $second): array
    {
        $lap = (int) array_key_last($gaps);
        $gap = $gaps[$lap];

        return [
            'from' => $lap,
            'to' => $lap,
            'type' => 'finish',
            'label' => 'Финал',
            'detail' => $gap > 0
                ? sprintf('%s завършва пред %s с %s с.', $names[$first], $names[$second], $this->decimal($gap))
                : sprintf('Крайна разлика по време: %s с.', $this->decimal(abs($gap))),
            'drivers' => [$first, $second],
            'delta' => null,
        ];
    }

    /**
     * @param  array{sign:int, from:int, to:int, delta:float}  $segment
     * @param  array<int, string>  $names
     * @return array{from:int, to:int, type:string, label:string, detail:string, drivers:array<int, int>, delta:float}
     */
    private function segmentMoment(RaceDataBundle $bundle, array $segment, array $names, int $first, int $second): array
    {
        $stops = $this->stopsInside($bundle, $segment, $names, $first, $second);
        $type = match (true) {
            $stops !== [] => 'pit',
            $this->underNeutralisation($bundle, $segment) => 'neutralisation',
            default => 'pace',
        };

        return [
            'from' => $segment['from'],
            'to' => $segment['to'],
            'type' => $type,
            'label' => $segment['from'] === $segment['to']
                ? sprintf('Обиколка %d', $segment['from'])
                : sprintf('Обиколки %d–%d', $segment['from'], $segment['to']),
            'detail' => match ($type) {
                'pit' => $this->stopsPhrase($stops).' '.$this->gapPhrase($segment['delta']),
                'neutralisation' => 'Неутрализация на трасето. '.$this->gapPhrase($segment['delta']),
                default => $this->pacePhrase($segment, $names, $first, $second),
            },
            'drivers' => [$first, $second],
            'delta' => $segment['delta'],
        ];
    }

    /**
     * Спиранията на двамата вътре в сегмента, вече като текст „име — обиколка N“.
     *
     * @param  array{sign:int, from:int, to:int, delta:float}  $segment
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    private function stopsInside(RaceDataBundle $bundle, array $segment, array $names, int $first, int $second): array
    {
        return $bundle->pits
            ->filter(fn (array $p) => in_array((int) ($p['driver_number'] ?? 0), [$first, $second], true))
            ->filter(function (array $p) use ($segment): bool {
                $lap = (int) ($p['lap_number'] ?? 0);

                return $lap >= $segment['from'] && $lap <= $segment['to'];
            })
            ->sortBy(fn (array $p) => (int) $p['lap_number'])
            ->map(fn (array $p) => sprintf(
                '%s — обиколка %d',
                $names[(int) $p['driver_number']],
                (int) $p['lap_number'],
            ))
            ->values()
            ->all();
    }

    /** @param  array{sign:int, from:int, to:int, delta:float}  $segment */
    private function underNeutralisation(RaceDataBundle $bundle, array $segment): bool
    {
        foreach ($bundle->neutralisationWindows() as $window) {
            if ($segment['from'] <= $window['to'] && $segment['to'] >= $window['from']) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<int, string>  $stops */
    private function stopsPhrase(array $stops): string
    {
        return (count($stops) === 1 ? 'Питстоп: ' : 'Питстопове: ').implode(', ', $stops).'.';
    }

    private function gapPhrase(float $delta): string
    {
        return sprintf(
            'Разликата между първите двама %s с %s с.',
            $delta < 0 ? 'намалява' : 'расте',
            $this->decimal(abs($delta)),
        );
    }

    /**
     * Темпото се описва и като скорост на промяна: „общо 3,5 с“ за седем
     * обиколки и за една са различни неща, а само сборът не ги различава.
     *
     * @param  array{sign:int, from:int, to:int, delta:float}  $segment
     * @param  array<int, string>  $names
     */
    private function pacePhrase(array $segment, array $names, int $first, int $second): string
    {
        $total = abs($segment['delta']);
        $laps = $segment['to'] - $segment['from'] + 1;
        $name = $segment['delta'] < 0 ? $names[$second] : $names[$first];
        $verb = $segment['delta'] < 0 ? 'наваксва' : 'се откъсва';

        if ($laps === 1) {
            return sprintf('%s %s с %s с.', $name, $verb, $this->decimal($total));
        }

        return sprintf(
            '%s %s с %s с на обиколка, общо %s с за %d обиколки.',
            $name,
            $verb,
            $this->decimal($total / $laps, 2),
            $this->decimal($total),
            $laps,
        );
    }

    /** Десетичните числа на български се пишат със запетая. */
    private function decimal(float $value, int $precision = 1): string
    {
        return str_replace('.', ',', number_format(round($value, $precision), $precision, '.', ''));
    }
}
