<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\GameSession;
use App\Models\GameVisit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Отваряне на играта (view — всеки) или гост-„Карай" (start — само без
 * акаунт; регистрираните стартове са в game_sessions). Отварянето се брои
 * от клиента, не от контролера: hover prefetch-ът на менюто прави заявка
 * към /game, която после се ползва като самата навигация — сървърът не
 * може да различи „надвесен курсор" от „влязъл". Mount-ът на страницата може.
 */
class StoreGameVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->input('kind') !== GameVisit::KIND_START || $this->user() === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in([GameVisit::KIND_VIEW, GameVisit::KIND_START])],
            'track' => ['exclude_unless:kind,'.GameVisit::KIND_START, 'required', 'string', Rule::in(array_keys((array) config('game.tracks', [])))],
            'device' => ['required', 'string', Rule::in([GameSession::DEVICE_MOBILE, GameSession::DEVICE_DESKTOP])],
        ];
    }
}
