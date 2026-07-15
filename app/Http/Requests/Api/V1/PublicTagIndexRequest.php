<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class PublicTagIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:80']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'q.string' => __('tags.validation.search'),
            'q.max' => __('tags.validation.search'),
        ];
    }

    public function search(): string
    {
        $value = $this->validated('q');

        return is_string($value) ? $value : '';
    }
}
