<?php

declare(strict_types=1);

namespace App\Http\Requests\Pwa;

use App\Services\Pwa\WebPushEndpointGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreWebPushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $endpoint = $this->input('endpoint');
        $locale = $this->input('locale');

        $this->merge([
            'endpoint' => is_string($endpoint) ? trim($endpoint) : $endpoint,
            'locale' => is_string($locale) ? mb_strtolower(trim($locale)) : $locale,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'installation_id' => ['required', 'uuid'],
            'endpoint' => ['required', 'string', 'max:2048', 'url:https'],
            'locale' => [
                'required',
                'string',
                Rule::in((array) config('catalog-collections.supported_locales', ['ru'])),
            ],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! app(WebPushEndpointGuard::class)->allows($this->input('endpoint'))) {
                $validator->errors()->add('endpoint', __('pwa.validation.push_endpoint_invalid'));
            }

            foreach (array_diff(array_keys($this->all()), ['installation_id', 'endpoint', 'locale']) as $extraKey) {
                $validator->errors()->add(
                    (string) $extraKey,
                    __('pwa.validation.field_unsupported'),
                );
            }
        }];
    }
}
