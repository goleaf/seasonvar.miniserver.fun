<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\ValueObjects\NormalizedEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::defaults()],
            'password_confirmation' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.required' => __('auth.validation.email_required'),
            'email.email' => __('auth.validation.email_format'),
            'email.max' => __('auth.validation.email_max'),
            'token.required' => __('auth.validation.token_required'),
            'token.max' => __('auth.validation.token_invalid'),
            'password.required' => __('auth.validation.new_password_required'),
            'password.confirmed' => __('auth.validation.password_confirmation_same'),
            'password.max' => __('auth.validation.password_max'),
            'password.min' => __('auth.validation.password_min'),
            'password.letters' => __('auth.validation.password_letters'),
            'password.mixed' => __('auth.validation.password_mixed'),
            'password.numbers' => __('auth.validation.password_numbers'),
            'password.symbols' => __('auth.validation.password_symbols'),
            'password_confirmation.required' => __('auth.validation.new_password_confirmation_required'),
            'password_confirmation.max' => __('auth.validation.password_max'),
        ];
    }

    /** @return array{email: string, token: string, password: string} */
    public function resetData(): array
    {
        return [
            'email' => $this->string('email')->toString(),
            'token' => $this->string('token')->toString(),
            'password' => $this->string('password')->toString(),
        ];
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => NormalizedEmail::value($email)]);
        }
    }
}
