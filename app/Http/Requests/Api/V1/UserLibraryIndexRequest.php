<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\DTOs\UserLibraryFilters;
use App\Enums\CatalogPublicationType;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UserLibraryIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['q', 'type', 'tag', 'sort', 'direction'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $normalized[$key] = $key === 'q'
                    ? str($value)->squish()->toString()
                    : str($value)->trim()->lower()->toString();
            }
        }

        $this->merge($normalized);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $sorts = $this->routeIs('api.v1.me.ratings.index')
            ? ['updated', 'rating', 'title', 'year']
            : ['updated', 'title', 'year'];

        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:160'],
            'type' => ['sometimes', 'nullable', Rule::enum(CatalogPublicationType::class)],
            'year' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:'.(now()->year + 1)],
            'tag' => ['sometimes', 'nullable', 'uuid'],
            'sort' => ['sometimes', Rule::in($sorts)],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'q.string' => 'Поисковый запрос должен быть строкой.',
            'q.max' => 'Поисковый запрос не должен быть длиннее 160 символов.',
            'type.enum' => 'Выбран неизвестный тип публикации.',
            'year.integer' => 'Год должен быть целым числом.',
            'year.min' => 'Год должен быть не меньше 1900.',
            'year.max' => 'Указан недопустимый год.',
            'tag.uuid' => __('tags.validation.personal_tag'),
            'sort.in' => 'Выбран недоступный способ сортировки.',
            'direction.in' => 'Направление сортировки должно быть asc или desc.',
            'page.integer' => 'Номер страницы должен быть целым числом.',
            'page.min' => 'Номер страницы должен быть не меньше 1.',
            'per_page.integer' => 'Размер страницы должен быть целым числом.',
            'per_page.min' => 'Размер страницы должен быть не меньше 1.',
            'per_page.max' => 'Размер страницы не должен быть больше 50.',
        ];
    }

    public function filters(): UserLibraryFilters
    {
        $validated = $this->validated();

        return new UserLibraryFilters(
            query: (string) ($validated['q'] ?? ''),
            type: isset($validated['type']) ? (string) $validated['type'] : null,
            year: isset($validated['year']) ? (int) $validated['year'] : null,
            personalTagPublicId: isset($validated['tag']) ? (string) $validated['tag'] : null,
            sort: (string) ($validated['sort'] ?? 'updated'),
            direction: (string) ($validated['direction'] ?? 'desc'),
            perPage: (int) ($validated['per_page'] ?? config('mobile-api.default_per_page', 20)),
        );
    }
}
