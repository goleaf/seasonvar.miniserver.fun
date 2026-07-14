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
            'q' => ['required', 'string', 'min:2', 'max:80'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'q.required' => 'Введите запрос для поиска.',
            'q.string' => 'Поисковый запрос должен быть строкой.',
            'q.min' => 'Введите не менее 2 символов для поиска.',
            'q.max' => 'Поисковый запрос слишком длинный.',
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (mb_strlen(app(CatalogSearchNormalizer::class)->key($this->queryValue())) < 2) {
                    $validator->errors()->add('q', 'Введите не менее 2 букв или цифр для поиска.');
                }
            },
        ];
    }

    public function queryValue(): string
    {
        $query = $this->validated('q');

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
