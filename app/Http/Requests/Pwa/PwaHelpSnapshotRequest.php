<?php

declare(strict_types=1);

namespace App\Http\Requests\Pwa;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class PwaHelpSnapshotRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $locale = $this->query('locale');

        if (is_string($locale)) {
            $this->merge(['locale' => mb_strtolower(trim($locale))]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'locale' => [
                'required',
                'string',
                Rule::in((array) config('help-center.supported_locales', ['ru'])),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'locale.required' => __('pwa.validation.locale_required'),
            'locale.in' => __('pwa.validation.locale_invalid'),
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $unknown = array_diff(array_keys($this->query()), ['locale']);

                if ($unknown !== []) {
                    $validator->errors()->add('query', __('pwa.validation.query_invalid'));
                }
            },
        ];
    }

    public function locale(): string
    {
        return (string) $this->validated('locale');
    }
}
