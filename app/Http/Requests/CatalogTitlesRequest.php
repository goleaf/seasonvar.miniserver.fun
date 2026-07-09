<?php

namespace App\Http\Requests;

use App\Enums\CatalogFilterType;
use App\Rules\CatalogFilterSlug;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class CatalogTitlesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string|ValidationRule|Enum>>
     */
    public function rules(): array
    {
        $rules = [
            'q' => ['nullable', 'string', 'max:160'],
            'year' => ['nullable', 'string', 'max:16'],
            'title' => $this->slugRules(),
            'type' => ['nullable', Rule::enum(CatalogFilterType::class)],
            'taxonomy' => $this->slugRules(),
        ];

        foreach (CatalogFilterType::values() as $filterType) {
            $rules[$filterType] = $this->slugRules();
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.string' => 'Поисковый запрос должен быть строкой.',
            'q.max' => 'Поисковый запрос слишком длинный.',
            'year.string' => 'Год должен быть строкой.',
            'year.max' => 'Год слишком длинный.',
            'type.enum' => 'Выбран неподдерживаемый тип фильтра.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [
            'q' => 'поиск',
            'year' => 'год',
            'title' => 'контекст карточки',
            'type' => 'тип фильтра',
            'taxonomy' => 'значение фильтра',
        ];

        foreach (CatalogFilterType::cases() as $filterType) {
            $attributes[$filterType->value] = $filterType->label();
        }

        return $attributes;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (array_merge(['q', 'year', 'title', 'type', 'taxonomy'], CatalogFilterType::values()) as $key) {
            if (! $this->query->has($key) || ! is_scalar($this->query($key))) {
                continue;
            }

            $value = trim((string) $this->query($key));
            $normalized[$key] = $key === 'q'
                ? (preg_replace('/\s+/u', ' ', $value) ?: '')
                : $value;
        }

        $this->merge($normalized);
    }

    public function normalizedSearch(): string
    {
        $search = $this->stringQuery('q');
        $search = preg_replace('/\s+/u', ' ', trim($search)) ?: '';

        if (mb_strlen($search) < 2) {
            return '';
        }

        return mb_substr($search, 0, 80);
    }

    public function requestedYear(): string
    {
        return $this->stringQuery('year');
    }

    public function year(): ?int
    {
        $requestedYear = $this->requestedYear();
        $parsedYear = preg_match('/^\d{4}$/', $requestedYear) === 1 ? (int) $requestedYear : null;

        return $parsedYear !== null && $parsedYear >= 1900 && $parsedYear <= ((int) now()->format('Y') + 1)
            ? $parsedYear
            : null;
    }

    public function invalidYear(): bool
    {
        return $this->requestedYear() !== '' && $this->year() === null;
    }

    public function titleContextSlug(): ?string
    {
        return $this->filterSlug($this->query('title', ''));
    }

    /**
     * @param  array<int, string>  $filterTypes
     */
    public function legacyType(array $filterTypes): string
    {
        $value = $this->stringQuery('type');

        return in_array($value, $filterTypes, true) ? $value : '';
    }

    public function legacyTaxonomy(): ?string
    {
        return $this->filterSlug($this->query('taxonomy', ''));
    }

    public function filterSlug(mixed $value): ?string
    {
        return CatalogFilterSlug::normalize($value);
    }

    /**
     * @return list<string|ValidationRule>
     */
    private function slugRules(): array
    {
        return ['nullable', 'string', 'max:'.CatalogFilterSlug::MAX_LENGTH, new CatalogFilterSlug];
    }

    private function stringQuery(string $key): string
    {
        $value = $this->query($key, '');

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
