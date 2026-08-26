<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\WouldRecommend;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSurveyResponseRequest extends FormRequest
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
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'would_recommend' => ['required', Rule::enum(WouldRecommend::class)],
            // Свободният текст е по избор — двата клика по-горе стигат за отговор.
            'comment' => ['nullable', 'string', 'max:2000'],
            'source' => ['required', Rule::in(['prompt', 'page'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rating.required' => 'Моля, избери оценка от 1 до 5.',
            'rating.min' => 'Оценката е между 1 и 5.',
            'rating.max' => 'Оценката е между 1 и 5.',
            'would_recommend.required' => 'Моля, отговори би ли препоръчал Падок.',
            'would_recommend.enum' => 'Невалиден отговор.',
            'comment.max' => 'Коментарът може да е най-много 2000 знака.',
        ];
    }
}
