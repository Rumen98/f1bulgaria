<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // auth + verified се проверяват от middleware-а на рута.
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:2', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'Напиши нещо, преди да публикуваш.',
            'body.min' => 'Коментарът е твърде кратък.',
            'body.max' => 'Коментарът е твърде дълъг (до 2000 знака).',
        ];
    }
}
