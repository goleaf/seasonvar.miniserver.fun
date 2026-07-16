<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\ValueObjects\NormalizedEmail;
use Illuminate\Foundation\Http\FormRequest;

final class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['email' => ['required', 'string', 'email:rfc', 'max:255']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.required' => __('auth.validation.email_required'),
            'email.email' => __('auth.validation.email_format'),
            'email.max' => __('auth.validation.email_max'),
        ];
    }

    public function email(): string
    {
        return $this->string('email')->toString();
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => NormalizedEmail::value($email)]);
        }
    }
}
