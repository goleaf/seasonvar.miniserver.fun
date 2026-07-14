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
            'limit.integer' => 'Лимит должен быть целым числом.',
            'limit.min' => 'Лимит должен быть не меньше 1.',
            'limit.max' => 'Лимит не должен быть больше 24.',
            'page.integer' => 'Номер страницы должен быть целым числом.',
            'page.min' => 'Номер страницы должен быть не меньше 1.',
            'per_page.integer' => 'Размер страницы должен быть целым числом.',
            'per_page.min' => 'Размер страницы должен быть не меньше 1.',
            'per_page.max' => 'Размер страницы не должен быть больше 48.',
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
