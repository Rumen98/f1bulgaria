<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\GameSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGameSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && ! $this->user()->isBanned();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['sometimes', 'required', 'uuid'],
            'track' => ['required', 'string', Rule::in(array_keys((array) config('game.tracks', [])))],
            'device' => ['required', 'string', Rule::in([GameSession::DEVICE_MOBILE, GameSession::DEVICE_DESKTOP])],
            'mode' => ['required', 'string', Rule::in([GameSession::MODE_SOLO, GameSession::MODE_RACE])],
            'context' => ['sometimes', 'array:viewport_width,viewport_height,pixel_ratio,controls,graphics,transmission,sim_version,browser,os'],
            'context.viewport_width' => ['sometimes', 'integer', 'between:1,16384'],
            'context.viewport_height' => ['sometimes', 'integer', 'between:1,16384'],
            'context.pixel_ratio' => ['sometimes', 'decimal:0,4', 'between:0.1,10'],
            'context.controls' => ['sometimes', Rule::in(['buttons', 'tilt', 'keyboard'])],
            'context.graphics' => ['sometimes', Rule::in(['auto', 'low', 'medium', 'high', 'ultra'])],
            'context.transmission' => ['sometimes', Rule::in(['auto', 'manual'])],
            'context.sim_version' => ['sometimes', 'integer', 'between:1,10000'],
            'context.browser' => ['sometimes', Rule::in(['safari', 'chrome', 'firefox', 'edge', 'other'])],
            'context.os' => ['sometimes', Rule::in(['ios', 'android', 'windows', 'macos', 'linux', 'other'])],
        ];
    }
}
