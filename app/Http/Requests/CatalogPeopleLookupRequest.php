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
            'type.required' => 'Выберите тип поиска людей.',
            'type.string' => 'Тип поиска людей должен быть строкой.',
            'type.in' => 'Поддерживается поиск только по актёрам и режиссёрам.',
            'q.required' => 'Введите имя для поиска.',
            'q.string' => 'Имя для поиска должно быть строкой.',
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
