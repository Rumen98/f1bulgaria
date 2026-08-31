<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // публично търсене, без акаунт
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Таванът пази LIKE заявката от огромен низ, подаден през URL-а.
            'q' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function term(): string
    {
        return trim((string) ($this->validated()['q'] ?? ''));
    }
}
