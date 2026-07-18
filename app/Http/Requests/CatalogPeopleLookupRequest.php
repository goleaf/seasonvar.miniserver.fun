<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Catalog\Search\CatalogSearchNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CatalogPeopleLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(['actor', 'director'])],
            'q' => ['required', 'string', 'min:2', 'max:80'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'type.required' => __('catalog.search.validation.people_type_required'),
            'type.string' => __('catalog.search.validation.people_type_string'),
            'type.in' => __('catalog.search.validation.people_type_supported'),
            'q.required' => __('catalog.search.validation.people_query_required'),
            'q.string' => __('catalog.search.validation.people_query_string'),
            'q.min' => __('catalog.search.validation.people_query_minimum'),
            'q.max' => __('catalog.search.validation.people_query_maximum'),
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (mb_strlen(app(CatalogSearchNormalizer::class)->key($this->queryValue())) < 2) {
                    $validator->errors()->add('q', __('catalog.search.validation.people_query_meaningful'));
                }
            },
        ];
    }

    public function peopleType(): string
    {
        $type = $this->validated('type');

        return is_string($type) ? $type : '';
    }

    public function queryValue(): string
    {
        $query = $this->input('q');

        return is_string($query)
            ? app(CatalogSearchNormalizer::class)->display($query)
            : '';
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->query('type'))) {
            $this->merge(['type' => trim($this->query('type'))]);
        }

        if (is_string($this->query('q'))) {
            $this->merge([
                'q' => app(CatalogSearchNormalizer::class)->display($this->query('q')),
            ]);
        }
    }
}
