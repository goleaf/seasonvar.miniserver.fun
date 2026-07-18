<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Normalizer;

final class CatalogDirectoryIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:80'],
            'letter' => ['nullable', 'string', 'regex:/^(?:[A-Za-zА-Яа-яЁё]|#)$/u'],
            'sort' => ['nullable', 'string', Rule::in(['name_asc', 'count_desc'])],
            'decade' => ['nullable', 'integer', 'between:'.$this->minimumYear().','.$this->maximumYear(), 'multiple_of:10'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'q.string' => __('api.validation.directory.query_string'),
            'q.max' => __('api.validation.directory.query_maximum'),
            'letter.regex' => __('api.validation.directory.letter'),
            'sort.in' => __('api.validation.directory.sort'),
            'decade.multiple_of' => __('api.validation.directory.decade'),
            'per_page.max' => __('api.validation.pagination.per_page_maximum', ['maximum' => 50]),
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        $search = $this->query('q');

        if (is_string($search)) {
            if (class_exists(Normalizer::class) && ! Normalizer::isNormalized($search, Normalizer::FORM_KC)) {
                $search = Normalizer::normalize($search, Normalizer::FORM_KC) ?: $search;
            }

            $normalized['q'] = Str::squish(strip_tags($search));
        }

        $letter = $this->query('letter');

        if (is_string($letter)) {
            $normalized['letter'] = mb_strtoupper(trim($letter));
        }

        $this->merge($normalized);
    }

    public function search(): string
    {
        return $this->string('q')->toString();
    }

    public function letter(): string
    {
        return $this->string('letter')->toString();
    }

    public function sort(): string
    {
        return $this->string('sort', 'name_asc')->toString();
    }

    public function decade(): ?int
    {
        return $this->filled('decade') ? $this->integer('decade') : null;
    }

    public function perPage(): int
    {
        return $this->integer('per_page', (int) config('mobile-api.default_per_page', 20));
    }

    private function minimumYear(): int
    {
        return max(1900, (int) config('catalog.directories.minimum_year', 1900));
    }

    private function maximumYear(): int
    {
        $configured = config('catalog.directories.maximum_year');

        return is_numeric($configured)
            ? max($this->minimumYear(), (int) $configured)
            : now()->year + 1;
    }
}
