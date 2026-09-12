<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\GameFeedback;
use App\Models\GameSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGameFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && ! $user->isBanned()
            && ($user->gameSessions()->exists() || $user->gameLapRecords()->exists());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'session_id' => ['bail', 'nullable', 'integer', 'min:1', Rule::exists(GameSession::class, 'id')->where('user_id', $this->user()?->id)],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'controls_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'performance_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'difficulty' => ['nullable', Rule::in(array_keys(GameFeedback::DIFFICULTY_LABELS))],
            'reason' => ['nullable', Rule::in(array_keys(GameFeedback::REASON_LABELS))],
            'would_play_again' => ['nullable', Rule::in(array_keys(GameFeedback::WOULD_PLAY_AGAIN_LABELS))],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'session_id.integer' => 'Карането не е разпознато. Отвори играта отново и опитай пак.',
            'session_id.min' => 'Карането не е разпознато. Отвори играта отново и опитай пак.',
            'session_id.exists' => 'Избери свое каране за обратната връзка.',
            'rating.required' => 'Избери обща оценка за играта от 1 до 5.',
            '*.integer' => 'Оценката трябва да е цяло число.',
            '*.min' => 'Оценката е между 1 и 5.',
            '*.max' => 'Оценката е между 1 и 5.',
            'comment.max' => 'Можеш да напишеш до 2000 знака.',
            'difficulty.in' => 'Избери една от посочените трудности.',
            'reason.in' => 'Избери една от посочените причини.',
            'would_play_again.in' => 'Избери дали би играл отново.',
        ];
    }
}
