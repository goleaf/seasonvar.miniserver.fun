<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Catalog\Search\CatalogSearchNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class GlobalSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:80'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'q.string' => __('catalog.search.validation.query_string'),
            'q.max' => __('catalog.search.validation.query_maximum'),
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $query = $this->queryValue();

                if ($query !== '' && app(CatalogSearchNormalizer::class)->key($query) === '') {
                    $validator->errors()->add('q', __('catalog.search.validation.query_meaningful'));
                }
            },
        ];
    }

    public function queryValue(): string
    {
        $query = $this->validated('q', '');

        return is_string($query) ? $query : '';
    }

    protected function prepareForValidation(): void
    {
        $query = $this->query('q');

        if (is_string($query)) {
            $this->merge([
                'q' => app(CatalogSearchNormalizer::class)->display($query),
            ]);
        }
    }
}
