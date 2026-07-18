<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class ViewingHistoryIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:24'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:48'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'limit.integer' => __('api.validation.pagination.limit_integer'),
            'limit.min' => __('api.validation.pagination.limit_minimum'),
            'limit.max' => __('api.validation.pagination.limit_maximum', ['maximum' => 24]),
            'page.integer' => __('api.validation.pagination.page_integer'),
            'page.min' => __('api.validation.pagination.page_minimum'),
            'per_page.integer' => __('api.validation.pagination.per_page_integer'),
            'per_page.min' => __('api.validation.pagination.per_page_minimum'),
            'per_page.max' => __('api.validation.pagination.per_page_maximum', ['maximum' => 48]),
        ];
    }

    public function limit(): int
    {
        return $this->integer('limit', 12);
    }

    public function perPage(): int
    {
        return $this->integer('per_page', (int) config('mobile-api.default_per_page', 20));
    }
}
