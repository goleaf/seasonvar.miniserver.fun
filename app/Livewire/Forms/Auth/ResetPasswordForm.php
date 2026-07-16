<?php

declare(strict_types=1);

namespace App\Livewire\Forms\Auth;

use App\ValueObjects\NormalizedEmail;
use Illuminate\Validation\Rules\Password;
use Livewire\Form;

final class ResetPasswordForm extends Form
{
    public string $email = '';

    public string $token = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    /** @return array{email: string, token: string, password: string} */
    public function validatedData(): array
    {
        $this->email = NormalizedEmail::value($this->email);
        $validated = $this->validate();

        return [
            'email' => $validated['email'],
            'token' => $validated['token'],
            'password' => $validated['password'],
        ];
    }

    /** @return array<string, list<mixed>> */
    protected function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255', Password::defaults()],
            'passwordConfirmation' => ['required', 'string', 'max:255', 'same:password'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'email.required' => __('auth.validation.email_required'),
            'email.email' => __('auth.validation.email_format'),
            'email.max' => __('auth.validation.email_max'),
            'token.required' => __('auth.validation.token_required'),
            'token.max' => __('auth.validation.token_invalid'),
            'password.required' => __('auth.validation.new_password_required'),
            'password.max' => __('auth.validation.password_max'),
            'password.min' => __('auth.validation.password_min'),
            'password.letters' => __('auth.validation.password_letters'),
            'password.mixed' => __('auth.validation.password_mixed'),
            'password.numbers' => __('auth.validation.password_numbers'),
            'password.symbols' => __('auth.validation.password_symbols'),
            'passwordConfirmation.required' => __('auth.validation.new_password_confirmation_required'),
            'passwordConfirmation.max' => __('auth.validation.password_max'),
            'passwordConfirmation.same' => __('auth.validation.password_confirmation_same'),
        ];
    }
}
