<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreGameLapRequest extends FormRequest
{
    /**
     * Никоя обиколка не може да е по-бърза от дължина / максимална скорост на
     * болида (config-ната maxSpeed е 92 m/s). Долна граница срещу измислени
     * рекорди. Пълната защита е server replay на записан вход — това е базова.
     */
    private const MAX_SPEED = 92.0;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tracks = array_keys((array) config('game.tracks', []));

        return [
            'track' => ['required', 'string', Rule::in($tracks)],
            'lap_ms' => ['required', 'integer', 'min:10000', 'max:1200000'],
            // Изрични индекси 0/1/2 → входът задължително е СПИСЪК с 3 елемента.
            // Само `array|size:3` пропуска асоциативен обект, който после чупи
            // record() (чете индекси 0/1/2 → NULL в NOT NULL колони → 500).
            'sectors' => ['required', 'array', 'size:3'],
            'sectors.0' => ['required', 'integer', 'min:1', 'max:1200000'],
            'sectors.1' => ['required', 'integer', 'min:1', 'max:1200000'],
            'sectors.2' => ['required', 'integer', 'min:1', 'max:1200000'],
            // Записът на входа за сървърно преиграване е ЗАДЪЛЖИТЕЛЕН — без
            // него валидацията е по желание, т.е. никаква. Таванът покрива
            // 20-минутната обиколка (2 байта/тик × 120 Hz, base64).
            'trace' => ['required', 'string', 'max:620000'],
            'sim_version' => ['required', 'integer', 'min:1', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $sectors = $this->input('sectors');
            $lap = $this->input('lap_ms');

            if (! is_array($sectors) || count($sectors) !== 3 || ! is_numeric($lap)) {
                return;
            }

            // Секторите ТРЯБВА да са списък [0,1,2] по ред. Разбъркан обект
            // (напр. {"2":..,"0":..,"1":..}) минава rules() (ключове 0/1/2 са
            // налични), но би заобиколил проверките по-долу — затова се ОТХВЪРЛЯ,
            // а не се пропуска (иначе анти-чийт прагът се байпасва).
            if (! array_is_list($sectors)) {
                $validator->errors()->add('sectors', 'Невалиден формат на секторите.');

                return;
            }

            // Секторите трябва да съставят обиколката (лека защита срещу подправяне).
            // Толерансът покрива само закръгляне тик→ms.
            $sum = array_sum(array_map('intval', $sectors));

            if (abs($sum - (int) $lap) > 100) {
                $validator->errors()->add('sectors', 'Секторните времена не съставят обиколката.');
            }

            // Правдоподобност спрямо дължината на пистата: под дължина/макс.
            // скорост е физически невъзможно.
            $length = $this->trackLengthMeters((string) $this->input('track'));

            if ($length !== null) {
                $floorMs = (int) floor(($length / self::MAX_SPEED) * 1000);

                if ((int) $lap < $floorMs) {
                    $validator->errors()->add('lap_ms', 'Времето е неправдоподобно бързо за тази писта.');
                }
            }
        });
    }

    /**
     * Дължината на пистата в метри от каталога (public/game-tracks/index.json).
     */
    private function trackLengthMeters(string $slug): ?float
    {
        $tracks = Cache::remember('game.tracks.index', now()->addHour(), function (): array {
            $path = public_path('game-tracks/index.json');

            if (! file_exists($path)) {
                return [];
            }

            try {
                $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }

            return is_array($decoded) ? $decoded : [];
        });

        foreach ($tracks as $track) {
            if (($track['slug'] ?? null) === $slug && isset($track['length'])) {
                return (float) $track['length'];
            }
        }

        return null;
    }
}
