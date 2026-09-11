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
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'track' => ['required', 'string', Rule::in(array_keys((array) config('game.tracks', [])))],
            'device' => ['required', 'string', Rule::in([GameSession::DEVICE_MOBILE, GameSession::DEVICE_DESKTOP])],
            'mode' => ['required', 'string', Rule::in([GameSession::MODE_SOLO, GameSession::MODE_RACE])],
        ];
    }
}
