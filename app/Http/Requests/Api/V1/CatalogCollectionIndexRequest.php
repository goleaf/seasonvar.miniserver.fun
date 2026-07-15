<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class CatalogCollectionIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['featured', 'recent', 'title'])],
            'per_page' => ['nullable', 'integer', Rule::in([12, 18, 24, 36])],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }

    public function search(): string
    {
        return Str::limit(Str::squish((string) $this->validated('q', '')), 100, '');
    }

    public function sort(): string
    {
        return (string) $this->validated('sort', 'featured');
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 18);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'q' => Str::limit(Str::squish((string) $this->input('q', '')), 100, ''),
            'sort' => $this->input('sort', 'featured'),
            'per_page' => $this->input('per_page', 18),
        ]);
    }
}
