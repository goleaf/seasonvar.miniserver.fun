<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Services\Catalog\Search\CatalogSearchNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class SearchSuggestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:1', 'max:80'],
            'scope' => ['sometimes', 'string', 'in:header_titles,header_portal'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'q.required' => __('catalog.search.validation.query_required'),
            'q.string' => __('catalog.search.validation.query_string'),
            'q.min' => __('catalog.search.validation.query_minimum'),
            'q.max' => __('catalog.search.validation.query_maximum'),
            'scope.in' => __('catalog.search.validation.scope_supported'),
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $length = mb_strlen(app(CatalogSearchNormalizer::class)->key($this->queryValue()));
                $portalOnly = $this->input('scope') === 'header_portal';

                if ($length === 0 || ($portalOnly && $length < 2)) {
                    $validator->errors()->add('q', $length === 0
                        ? __('catalog.search.validation.query_meaningful')
                        : __('catalog.search.validation.portal_minimum'));
                }
            },
        ];
    }

    public function queryValue(): string
    {
        $query = $this->validated('q');

        return is_string($query) ? $query : '';
    }

    public function scopeValue(): ?string
    {
        $scope = $this->validated('scope');

        return is_string($scope) ? $scope : null;
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
