<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SwitchInterfaceLocaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'locale' => ['required', 'string', Rule::in((array) config('catalog-collections.supported_locales', []))],
            'return_to' => ['required', 'string', 'max:2048'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'locale.required' => __('catalog.locale.validation.required'),
            'locale.in' => __('catalog.locale.validation.supported'),
            'return_to.required' => __('catalog.locale.validation.return_to'),
            'return_to.max' => __('catalog.locale.validation.return_to'),
        ];
    }
}
