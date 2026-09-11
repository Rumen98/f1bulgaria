<?php

declare(strict_types=1);

namespace App\Services\Og;

use GdImage;

/**
 * Картинката за споделяне на едно състезание от „Данни“ — очертанието на
 * пистата върху тъмния фон на сайта.
 *
 * Защо: og:image е единственото, което Telegram, Facebook и Viber показват до
 * заглавието, а дотук всяка страница от раздела споделяше един и същ брандов
 * банер. Раздел, направен да се споделя, изглеждаше в емисията като всяка
 * друга страница.
 *
 * Формата не идва отникъде ново: `charts['track_map']` вече я носи, записана
 * при генерирането на рекапа. Нула заявки навън.
 *
 * БЕЗ ТЕКСТ в картинката. Сайтът е на Figtree, който няма кирилица, а да
 * влачим шрифтов файл в репото заради това не си струва — платформите и без
 * това показват og:title като текст до картинката.
 */
class CircuitOgImage
{
    public const WIDTH = 1200;

    public const HEIGHT = 630;

    /**
     * Рисува се на двоен размер и се смалява накрая.
     *
     * GD няма изглаждане за дебели линии — `imageantialias` работи само при
     * дебелина 1. Смаляването на двойния кадър върши същата работа и е
     * единственото, което пази очертанието да не излезе стъпаловидно.
     */
    private const SCALE = 2;

    /** Полета около очертанието, в крайни пиксели. */
    private const PADDING_X = 96;

    private const PADDING_TOP = 56;

    private const PADDING_BOTTOM = 64;

    /** Дебелина на линията на пистата. */
    private const STROKE = 7;

    /** Ляв червен кант — същият акцент като заглавията в сайта. */
    private const RULE = 12;

    /** Радиус на точката на старт-финала: червената точка от бранда, върху трасето. */
    private const START_DOT = 12;

    /** Тъмният пръстен около нея — отделя я от бялата линия отдолу. */
    private const START_HALO = 5;

    /** Радиус на червеното сияние зад пистата. */
    private const GLOW = 430;

    /** Под този брой точки „очертанието“ е шум, а не писта (както в CircuitGeometry). */
    private const MIN_POINTS = 20;

    /** @var array{0:int, 1:int, 2:int} Фонът на сайта. */
    private const GROUND = [0x0A, 0x0A, 0x0A];

    /** @var array{0:int, 1:int, 2:int} zinc-100 — текстовият цвят на сайта. */
    private const TRACK = [0xF4, 0xF4, 0xF5];

    /** @var array{0:int, 1:int, 2:int} Брандовото червено (tailwind.config.js). */
    private const BRAND = [0xE1, 0x06, 0x00];

    /**
     * Има ли изобщо от какво да се нарисува картинка.
     *
     * Ползва се в рендер пътя на страницата, за да реши дали да сочи насам.
     * Минава по СЪЩАТА извадка като render(): разминат ли се двете, страницата
     * обявява og:image, който връща 404, и в емисията не остава никаква
     * картинка — по-лошо от общия банер.
     *
     * @param  array<string, mixed>|null  $trackMap
     */
    public function hasShape(?array $trackMap): bool
    {
        return $this->outline($trackMap) !== null;
    }

    /**
     * Готовият PNG, или null когато няма форма за рисуване.
     *
     * @param  array<string, mixed>|null  $trackMap
     */
    public function render(?array $trackMap): ?string
    {
        $outline = $this->outline($trackMap);

        if ($outline === null) {
            return null;
        }

        $rotation = is_numeric($trackMap['rotation'] ?? null) ? (float) $trackMap['rotation'] : 0.0;
        $shape = $this->project($this->rotate($outline, $rotation));

        $canvas = imagecreatetruecolor(self::WIDTH * self::SCALE, self::HEIGHT * self::SCALE);
        imagealphablending($canvas, true);

        imagefilledrectangle($canvas, 0, 0, self::WIDTH * self::SCALE, self::HEIGHT * self::SCALE, $this->colour($canvas, self::GROUND));
        $this->glow($canvas, $shape);
        $this->stroke($canvas, $shape);
        $this->startDot($canvas, $shape[0]);
        $this->rule($canvas);

        return $this->encode($canvas);
    }

    /**
     * Точките, които описват пистата — в собствената им координатна система.
     *
     * Очертанието от MultiViewer е по-чисто, но липсва, когато Cloudflare е
     * отказал или сезонът е бил непознат. Тогава линията на болида описва
     * същата писта — TrackSpeedMap.vue пада точно натам и картата пак се
     * получава.
     *
     * @param  array<string, mixed>|null  $trackMap
     * @return array<int, array{0: float, 1: float}>|null
     */
    private function outline(?array $trackMap): ?array
    {
        if ($trackMap === null) {
            return null;
        }

        $source = $trackMap['outline'] ?? null;

        if (! is_array($source) || count($source) < self::MIN_POINTS) {
            $source = $trackMap['points'] ?? null;
        }

        if (! is_array($source)) {
            return null;
        }

        $points = [];

        foreach ($source as $point) {
            // Точките на болида носят и скорост на трети индекс — тук интересът
            // е само към формата.
            if (is_array($point) && isset($point[0], $point[1]) && is_numeric($point[0]) && is_numeric($point[1])) {
                $points[] = [(float) $point[0], (float) $point[1]];
            }
        }

        return count($points) >= self::MIN_POINTS ? $points : null;
    }

    /**
     * Ъгълът, при който пистата стои както по телевизията.
     *
     * @param  array<int, array{0: float, 1: float}>  $points
     * @return array<int, array{0: float, 1: float}>
     */
    private function rotate(array $points, float $degrees): array
    {
        $cos = cos(deg2rad($degrees));
        $sin = sin(deg2rad($degrees));

        return array_map(
            fn (array $p): array => [$p[0] * $cos - $p[1] * $sin, $p[0] * $sin + $p[1] * $cos],
            $points,
        );
    }

    /**
     * Вписване в полето с ЕДИН мащаб по двете оси — различен мащаб сплесква
     * пистата и тя престава да се разпознава.
     *
     * @param  array<int, array{0: float, 1: float}>  $points
     * @return array<int, array{0: int, 1: int}>
     */
    private function project(array $points): array
    {
        $xs = array_column($points, 0);
        $ys = array_column($points, 1);
        $minX = min($xs);
        $minY = min($ys);
        $maxY = max($ys);

        $spanX = max(1e-6, max($xs) - $minX);
        $spanY = max(1e-6, $maxY - $minY);

        $left = $this->px(self::RULE + self::PADDING_X);
        $top = $this->px(self::PADDING_TOP);
        $width = $this->px(self::WIDTH - self::RULE - self::PADDING_X * 2);
        $height = $this->px(self::HEIGHT - self::PADDING_TOP - self::PADDING_BOTTOM);

        $scale = min($width / $spanX, $height / $spanY);
        $offsetX = $left + ($width - $spanX * $scale) / 2;
        $offsetY = $top + ($height - $spanY * $scale) / 2;

        return array_map(
            fn (array $p): array => [
                (int) round($offsetX + ($p[0] - $minX) * $scale),
                // y се обръща: в растера расте надолу.
                (int) round($offsetY + ($maxY - $p[1]) * $scale),
            ],
            $points,
        );
    }

    /**
     * Червеното сияние зад пистата, натрупано от концентрични елипси — GD няма
     * градиенти. Всяка е почти прозрачна, така че краят се губи плавно, вместо
     * да остави видим кръг.
     *
     * @param  array<int, array{0: int, 1: int}>  $shape
     */
    private function glow(GdImage $canvas, array $shape): void
    {
        $xs = array_column($shape, 0);
        $ys = array_column($shape, 1);
        $centreX = (int) round((min($xs) + max($xs)) / 2);
        $centreY = (int) round((min($ys) + max($ys)) / 2);

        $colour = imagecolorallocatealpha($canvas, self::BRAND[0], self::BRAND[1], self::BRAND[2], 126);
        $radius = $this->px(self::GLOW);
        $steps = 44;

        for ($i = $steps; $i > 0; $i--) {
            $diameter = (int) round(2 * $radius * $i / $steps);
            imagefilledellipse($canvas, $centreX, $centreY, $diameter, $diameter, $colour);
        }
    }

    /**
     * Линията на пистата — затворена, защото последната точка се връща на
     * старт-финалната права.
     *
     * Всяка отсечка е правоъгълник, а всяка става — кръгче. Причината да не е
     * `imagesetthickness` + `imageline`: GD рисува дебелата линия с квадратна
     * четка, тоест по диагонал излиза с една и половина по-широка, а по остър
     * завой оставя зъбер. Разликата се вижда точно там, където очертанието на
     * пистата е най-характерно.
     *
     * @param  array<int, array{0: int, 1: int}>  $shape
     */
    private function stroke(GdImage $canvas, array $shape): void
    {
        $colour = $this->colour($canvas, self::TRACK);
        $half = $this->px(self::STROKE) / 2;
        $closed = [...$shape, $shape[0]];
        $previous = null;

        foreach ($closed as $point) {
            if ($previous !== null) {
                $this->segment($canvas, $previous, $point, $half, $colour);
            }

            imagefilledellipse($canvas, $point[0], $point[1], (int) round($half * 2), (int) round($half * 2), $colour);
            $previous = $point;
        }
    }

    /**
     * @param  array{0: int, 1: int}  $from
     * @param  array{0: int, 1: int}  $to
     */
    private function segment(GdImage $canvas, array $from, array $to, float $half, int $colour): void
    {
        $dx = $to[0] - $from[0];
        $dy = $to[1] - $from[1];
        $length = sqrt($dx * $dx + $dy * $dy);

        if ($length < 0.5) {
            return;
        }

        $nx = -$dy / $length * $half;
        $ny = $dx / $length * $half;

        imagefilledpolygon($canvas, [
            (int) round($from[0] + $nx), (int) round($from[1] + $ny),
            (int) round($to[0] + $nx), (int) round($to[1] + $ny),
            (int) round($to[0] - $nx), (int) round($to[1] - $ny),
            (int) round($from[0] - $nx), (int) round($from[1] - $ny),
        ], $colour);
    }

    /**
     * Старт-финалът: първата точка на очертанието стои точно там (виж
     * CircuitGeometry — краищата се пазят именно за да не зейне дупка на
     * правата).
     *
     * @param  array{0: int, 1: int}  $at
     */
    private function startDot(GdImage $canvas, array $at): void
    {
        $outer = $this->px(self::START_DOT + self::START_HALO);
        $inner = $this->px(self::START_DOT);

        imagefilledellipse($canvas, $at[0], $at[1], $outer * 2, $outer * 2, $this->colour($canvas, self::GROUND));
        imagefilledellipse($canvas, $at[0], $at[1], $inner * 2, $inner * 2, $this->colour($canvas, self::BRAND));
    }

    /**
     * Левият кант. Твърдо по ръба, защото Twitter реже 1200x630 до 2:1 и всеки
     * брандов елемент долу изчезва — вертикалната лента преживява рязането.
     */
    private function rule(GdImage $canvas): void
    {
        imagefilledrectangle($canvas, 0, 0, $this->px(self::RULE), self::HEIGHT * self::SCALE, $this->colour($canvas, self::BRAND));
    }

    /**
     * Смаляване до крайния размер и PNG в паметта — файлът се пише от
     * извикващия, ако изобщо се пише.
     */
    private function encode(GdImage $canvas): string
    {
        $out = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagecopyresampled($out, $canvas, 0, 0, 0, 0, self::WIDTH, self::HEIGHT, self::WIDTH * self::SCALE, self::HEIGHT * self::SCALE);

        ob_start();
        imagepng($out);

        return (string) ob_get_clean();
    }

    /**
     * @param  array{0:int, 1:int, 2:int}  $rgb
     */
    private function colour(GdImage $canvas, array $rgb): int
    {
        return (int) imagecolorallocate($canvas, ...$rgb);
    }

    /** Крайни пиксели → пиксели на работния кадър. */
    private function px(int $value): int
    {
        return $value * self::SCALE;
    }
}
