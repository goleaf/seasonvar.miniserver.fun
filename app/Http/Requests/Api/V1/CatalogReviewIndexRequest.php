<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class CatalogReviewIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'page.integer' => __('reviews.api_validation.page_integer'),
            'page.min' => __('reviews.api_validation.page_min'),
            'per_page.integer' => __('reviews.api_validation.per_page_integer'),
            'per_page.min' => __('reviews.api_validation.per_page_min'),
            'per_page.max' => __('reviews.api_validation.per_page_max'),
        ];
    }

    public function perPage(): int
    {
        return $this->integer('per_page', (int) config('mobile-api.default_per_page', 20));
    }
}
