<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\GameSession;
use App\Models\GameSessionEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreGameSessionEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $session = $this->route('session');
        abort_unless($session instanceof GameSession && $session->user_id === $this->user()?->id, 404);

        return ! $this->user()->isBanned();
    }

    /**
     * Числата са `decimal:0,4`, не голо `numeric`: `numeric|between` приема и
     * низ „0.000…1" от 200 kB, който щеше да легне дословно в JSON колоната.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'list', 'min:1', 'max:30'],
            'events.*' => ['required', 'array:sequence,type,active_ms,data'],
            'events.*.sequence' => ['required', 'integer', 'between:1,4096', 'distinct'],
            'events.*.type' => ['required', Rule::in(array_keys(GameSessionEvent::LABELS))],
            'events.*.active_ms' => ['required', 'integer', 'between:0,14400000'],
            'events.*.data' => ['sometimes', 'array:phase,sector,progress,speed,lap_ms,max_speed,frame_ms,render_scale,quality_tier,lap_valid,lap_number,race_position,warnings,error_code,save_status'],
            'events.*.data.phase' => ['sometimes', Rule::in(['formation', 'flying', 'finished'])],
            'events.*.data.sector' => ['sometimes', 'integer', 'between:1,3'],
            'events.*.data.progress' => ['sometimes', 'decimal:0,4', 'between:0,1'],
            'events.*.data.speed' => ['sometimes', 'decimal:0,4', 'between:0,500'],
            'events.*.data.lap_ms' => ['sometimes', 'nullable', 'integer', 'between:1,14400000'],
            'events.*.data.max_speed' => ['sometimes', 'decimal:0,4', 'between:0,500'],
            'events.*.data.frame_ms' => ['sometimes', 'decimal:0,4', 'between:0,10000'],
            'events.*.data.render_scale' => ['sometimes', 'decimal:0,4', 'between:0,4'],
            'events.*.data.quality_tier' => ['sometimes', Rule::in(['low-power', 'auto-full', 'auto-balanced', 'auto-safe', 'manual'])],
            'events.*.data.lap_valid' => ['sometimes', 'boolean'],
            'events.*.data.lap_number' => ['sometimes', 'integer', 'between:0,100'],
            'events.*.data.race_position' => ['sometimes', 'integer', 'between:1,6'],
            'events.*.data.warnings' => ['sometimes', 'integer', 'between:0,100'],
            'events.*.data.error_code' => ['sometimes', 'string', 'max:64', 'regex:/^[a-z0-9_:-]+$/'],
            'events.*.data.save_status' => ['sometimes', 'string', 'max:32', 'regex:/^[a-z0-9_-]+$/'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ((array) $this->input('events', []) as $index => $event) {
                if (! is_array($event)) {
                    continue;
                }
                if (($event['type'] ?? null) === 'lap_completed') {
                    // lap_ms може да е null (бойната първа обиколка на
                    // състезанието е без време), но ключът трябва да е подаден.
                    foreach (['lap_valid', 'lap_number'] as $field) {
                        if (! isset($event['data'][$field])) {
                            $validator->errors()->add("events.{$index}.data.{$field}", 'Липсват данни за завършената обиколка.');
                        }
                    }
                    if (! is_array($event['data'] ?? null) || ! array_key_exists('lap_ms', $event['data'])) {
                        $validator->errors()->add("events.{$index}.data.lap_ms", 'Липсват данни за завършената обиколка.');
                    }
                }
                if (($event['type'] ?? null) === 'race_completed' && $this->route('session')?->mode !== GameSession::MODE_RACE) {
                    $validator->errors()->add("events.{$index}.type", 'Това каране не е състезание.');
                }
            }
        }];
    }
}
