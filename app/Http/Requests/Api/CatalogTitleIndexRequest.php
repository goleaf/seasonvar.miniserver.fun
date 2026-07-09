<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CatalogTitleIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'page.integer' => 'Номер страницы должен быть числом.',
            'page.min' => 'Номер страницы должен быть больше нуля.',
            'per_page.integer' => 'Размер страницы должен быть числом.',
            'per_page.min' => 'Размер страницы должен быть больше нуля.',
            'per_page.max' => 'Размер страницы не должен быть больше 50.',
        ];
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 15);
    }
}
