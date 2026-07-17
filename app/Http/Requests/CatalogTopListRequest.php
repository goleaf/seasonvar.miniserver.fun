<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTOs\CatalogTopListFilters;
use App\Models\Country;
use App\Models\Genre;
use App\Rules\CatalogFilterSlug;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class CatalogTopListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $maximumYear = (int) now()->format('Y') + 1;

        return [
            'year_from' => ['nullable', 'integer', 'between:1900,'.$maximumYear],
            'year_to' => ['nullable', 'integer', 'between:1900,'.$maximumYear],
            'country' => [
                'nullable',
                'string',
                'max:'.CatalogFilterSlug::MAX_LENGTH,
                new CatalogFilterSlug,
                Rule::exists(Country::class, 'slug'),
            ],
            'genre' => [
                'nullable',
                'string',
                'max:'.CatalogFilterSlug::MAX_LENGTH,
                new CatalogFilterSlug,
                Rule::exists(Genre::class, 'slug'),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'year_from.integer' => __('top_lists.validation.year'),
            'year_from.between' => __('top_lists.validation.year'),
            'year_to.integer' => __('top_lists.validation.year'),
            'year_to.between' => __('top_lists.validation.year'),
            'country.string' => __('top_lists.validation.country'),
            'country.max' => __('top_lists.validation.country'),
            'country.exists' => __('top_lists.validation.country'),
            'genre.string' => __('top_lists.validation.genre'),
            'genre.max' => __('top_lists.validation.genre'),
            'genre.exists' => __('top_lists.validation.genre'),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'year_from' => __('top_lists.attributes.year_from'),
            'year_to' => __('top_lists.attributes.year_to'),
            'country' => __('top_lists.attributes.country'),
            'genre' => __('top_lists.attributes.genre'),
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $yearFrom = $this->yearValue('year_from');
                $yearTo = $this->yearValue('year_to');

                if ($yearFrom !== null && $yearTo !== null && $yearFrom > $yearTo) {
                    $validator->errors()->add('year_from', __('top_lists.validation.range'));
                }
            },
        ];
    }

    public function filters(): CatalogTopListFilters
    {
        $validated = $this->validated();

        return new CatalogTopListFilters(
            yearFrom: isset($validated['year_from']) ? (int) $validated['year_from'] : null,
            yearTo: isset($validated['year_to']) ? (int) $validated['year_to'] : null,
            country: isset($validated['country']) && is_string($validated['country'])
                ? $validated['country']
                : null,
            genre: isset($validated['genre']) && is_string($validated['genre'])
                ? $validated['genre']
                : null,
        );
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['year_from', 'year_to', 'country', 'genre'] as $key) {
            if (! $this->query->has($key)) {
                continue;
            }

            $value = $this->query($key);
            $value = is_scalar($value) ? trim((string) $value) : '';
            $normalized[$key] = $value !== '' ? $value : null;
        }

        $this->merge($normalized);
    }

    private function yearValue(string $key): ?int
    {
        $value = $this->input($key);

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
