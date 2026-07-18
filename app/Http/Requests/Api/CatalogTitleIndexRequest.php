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
            'page.integer' => __('api.validation.pagination.page_integer'),
            'page.min' => __('api.validation.pagination.page_minimum'),
            'per_page.integer' => __('api.validation.pagination.per_page_integer'),
            'per_page.min' => __('api.validation.pagination.per_page_minimum'),
            'per_page.max' => __('api.validation.pagination.per_page_maximum', ['maximum' => 50]),
        ];
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 15);
    }
}
