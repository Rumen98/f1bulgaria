<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuizRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // публично, без акаунт
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'answers' => ['required', 'array', 'min:1', 'max:50'],
            'answers.*.id' => ['required', 'integer', 'distinct'],
            'answers.*.choice' => ['nullable', 'integer', 'between:1,4'],
        ];
    }
}
