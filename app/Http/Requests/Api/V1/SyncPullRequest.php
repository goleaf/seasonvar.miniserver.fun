<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class SyncPullRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cursor.string' => __('api.validation.sync.cursor_string'),
            'cursor.max' => __('api.validation.sync.cursor_maximum'),
            'limit.integer' => __('api.validation.pagination.limit_integer'),
            'limit.min' => __('api.validation.pagination.limit_minimum'),
            'limit.max' => __('api.validation.pagination.limit_maximum', ['maximum' => 200]),
        ];
    }

    public function cursor(): ?string
    {
        $cursor = $this->validated('cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    public function limit(): int
    {
        return $this->integer('limit', (int) config('mobile-api.sync.default_pull_items', 100));
    }
}
