<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class CatalogCollectionShowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:6', 'max:48'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 24);
    }
}
