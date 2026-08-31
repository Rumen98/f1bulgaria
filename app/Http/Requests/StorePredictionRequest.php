<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Race;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePredictionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Race $race */
        $race = $this->route('race');

        // Пилотите трябва да са от сезона на състезанието.
        $driverExists = Rule::exists('drivers', 'id')
            ->where('season_id', $race->season_id);

        // Задължителен е само подиумът — него го познава всеки, който гледа
        // неделя. Pole, най-бърза обиколка, DNF и safety car искат човек, който
        // следи тренировките; като задължителни те отказваха случайния фен още
        // преди да е стигнал до бутона.
        return [
            'p1_driver_id' => ['required', 'integer', $driverExists, 'different:p2_driver_id', 'different:p3_driver_id'],
            'p2_driver_id' => ['required', 'integer', $driverExists, 'different:p3_driver_id'],
            'p3_driver_id' => ['required', 'integer', $driverExists],
            'pole_driver_id' => ['nullable', 'integer', $driverExists],
            'fastest_lap_driver_id' => ['nullable', 'integer', $driverExists],
            'dnf_count' => ['nullable', 'integer', 'min:0', 'max:20'],
            'safety_car' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'p1_driver_id.different' => 'Тримата на подиума трябва да са различни пилоти.',
            'p2_driver_id.different' => 'Тримата на подиума трябва да са различни пилоти.',
        ];
    }
}
