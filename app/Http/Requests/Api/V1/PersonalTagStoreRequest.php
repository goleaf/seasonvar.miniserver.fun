<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\DTOs\PersonalTagData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PersonalTagStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.config('tags.label_max_length', 80)],
            'description' => ['nullable', 'string', 'max:'.config('tags.personal_description_max_length', 1000)],
            'content_locale' => ['nullable', 'string', Rule::in(config('tags.supported_locales', []))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => __('tags.validation.name', [
                'min' => config('tags.label_min_length', 2),
                'max' => config('tags.label_max_length', 80),
            ]),
            'name.max' => __('tags.validation.name', [
                'min' => config('tags.label_min_length', 2),
                'max' => config('tags.label_max_length', 80),
            ]),
            'description.max' => __('tags.validation.description', ['max' => config('tags.personal_description_max_length', 1000)]),
            'content_locale.in' => __('tags.validation.locale'),
        ];
    }

    public function tagData(): PersonalTagData
    {
        return new PersonalTagData(
            name: (string) $this->validated('name'),
            description: is_string($this->validated('description')) ? $this->validated('description') : null,
            contentLocale: is_string($this->validated('content_locale')) ? $this->validated('content_locale') : null,
        );
    }
}
