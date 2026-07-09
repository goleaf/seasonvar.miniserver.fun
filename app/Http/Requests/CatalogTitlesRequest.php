<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CatalogTitlesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }

    public function normalizedSearch(): string
    {
        $search = is_scalar($this->query('q')) ? (string) $this->query('q') : '';
        $search = preg_replace('/\s+/u', ' ', trim($search)) ?: '';

        if (mb_strlen($search) < 2) {
            return '';
        }

        return mb_substr($search, 0, 80);
    }

    public function requestedYear(): string
    {
        $requestedYear = $this->query('year');

        return is_scalar($requestedYear) ? trim((string) $requestedYear) : '';
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
        $value = $this->query('type', '');

        if (! is_scalar($value)) {
            return '';
        }

        $value = trim((string) $value);

        return in_array($value, $filterTypes, true) ? $value : '';
    }

    public function legacyTaxonomy(): ?string
    {
        return $this->filterSlug($this->query('taxonomy', ''));
    }

    public function filterSlug(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        if (mb_strlen($value) > 120 || preg_match('/^[a-z0-9][a-z0-9-]*$/', $value) !== 1) {
            return null;
        }

        return $value;
    }
}
