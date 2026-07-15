<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class PersonalTagAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'tags' => ['present', 'array', 'max:'.config('tags.personal_assignment_limit', 50)],
            'tags.*' => ['required', 'uuid', 'distinct:strict'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'tags.present' => __('tags.errors.unauthorized_assignment'),
            'tags.array' => __('tags.errors.unauthorized_assignment'),
            'tags.max' => __('tags.errors.assignment_limit', [
                'count' => config('tags.personal_assignment_limit', 50),
            ]),
            'tags.*.required' => __('tags.validation.personal_tag'),
            'tags.*.uuid' => __('tags.validation.personal_tag'),
            'tags.*.distinct' => __('tags.validation.personal_tag'),
        ];
    }

    /** @return list<string> */
    public function tagPublicIds(): array
    {
        $tags = $this->validated('tags');

        if (! is_array($tags)) {
            return [];
        }

        return collect($tags)
            ->filter(fn (mixed $id): bool => is_string($id))
            ->values()
            ->all();
    }
}
