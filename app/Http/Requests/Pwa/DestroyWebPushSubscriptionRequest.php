<?php

declare(strict_types=1);

namespace App\Http\Requests\Pwa;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class DestroyWebPushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'installation_id' => ['required', 'uuid'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['installation_id']) as $extraKey) {
                $validator->errors()->add(
                    (string) $extraKey,
                    __('pwa.validation.field_unsupported'),
                );
            }
        }];
    }
}
