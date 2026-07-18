<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Services\Catalog\Search\CatalogSearchNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class HelpSuggestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'max:120'],
            'locale' => ['required', 'string', Rule::in((array) config('help-center.supported_locales', ['ru']))],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (mb_strlen(app(CatalogSearchNormalizer::class)->key($this->queryValue())) < 2) {
                $validator->errors()->add('q', __('help.errors.search_minimum'));
            }
        }];
    }

    public function queryValue(): string
    {
        $value = $this->validated('q');

        return is_string($value) ? $value : '';
    }

    public function localeValue(): string
    {
        $value = $this->validated('locale');

        return is_string($value) ? $value : (string) config('help-center.fallback_locale', 'ru');
    }

    protected function prepareForValidation(): void
    {
        $query = $this->query('q');
        $this->merge([
            'q' => is_string($query) ? app(CatalogSearchNormalizer::class)->display($query) : $query,
            'locale' => $this->query('locale', app()->getLocale()),
        ]);
    }
}
