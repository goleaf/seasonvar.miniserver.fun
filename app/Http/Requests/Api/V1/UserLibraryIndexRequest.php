<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\DTOs\UserLibraryFilters;
use App\Enums\CatalogPublicationType;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UserLibraryIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['q', 'type', 'tag', 'sort', 'direction'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $normalized[$key] = $key === 'q'
                    ? str($value)->squish()->toString()
                    : str($value)->trim()->lower()->toString();
            }
        }

        $this->merge($normalized);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $sorts = $this->routeIs('api.v1.me.ratings.index')
            ? ['updated', 'rating', 'title', 'year']
            : ['updated', 'title', 'year'];

        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:160'],
            'type' => ['sometimes', 'nullable', Rule::enum(CatalogPublicationType::class)],
            'year' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:'.(now()->year + 1)],
            'tag' => ['sometimes', 'nullable', 'uuid'],
            'sort' => ['sometimes', Rule::in($sorts)],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'q.string' => __('api.validation.library.query_string'),
            'q.max' => __('api.validation.library.query_maximum', ['maximum' => 160]),
            'type.enum' => __('api.validation.library.publication_type'),
            'year.integer' => __('api.validation.library.year_integer'),
            'year.min' => __('api.validation.library.year_minimum', ['minimum' => 1900]),
            'year.max' => __('api.validation.library.year_maximum'),
            'tag.uuid' => __('tags.validation.personal_tag'),
            'sort.in' => __('api.validation.library.sort'),
            'direction.in' => __('api.validation.library.direction'),
            'page.integer' => __('api.validation.pagination.page_integer'),
            'page.min' => __('api.validation.pagination.page_minimum'),
            'per_page.integer' => __('api.validation.pagination.per_page_integer'),
            'per_page.min' => __('api.validation.pagination.per_page_minimum'),
            'per_page.max' => __('api.validation.pagination.per_page_maximum', ['maximum' => 50]),
        ];
    }

    public function filters(): UserLibraryFilters
    {
        $validated = $this->validated();

        return new UserLibraryFilters(
            query: (string) ($validated['q'] ?? ''),
            type: isset($validated['type']) ? (string) $validated['type'] : null,
            year: isset($validated['year']) ? (int) $validated['year'] : null,
            personalTagPublicId: isset($validated['tag']) ? (string) $validated['tag'] : null,
            sort: (string) ($validated['sort'] ?? 'updated'),
            direction: (string) ($validated['direction'] ?? 'desc'),
            perPage: (int) ($validated['per_page'] ?? config('mobile-api.default_per_page', 20)),
        );
    }
}
