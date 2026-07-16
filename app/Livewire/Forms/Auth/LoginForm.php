<?php

declare(strict_types=1);

namespace App\Livewire\Forms\Auth;

use App\ValueObjects\NormalizedEmail;
use Livewire\Form;

final class LoginForm extends Form
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    /** @return array{email: string, password: string, remember: bool} */
    public function validatedData(): array
    {
        $this->email = NormalizedEmail::value($this->email);
        $validated = $this->validate();

        return [
            'email' => $validated['email'],
            'password' => $validated['password'],
            'remember' => $validated['remember'],
        ];
    }

    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'email.required' => __('auth.validation.email_required'),
            'email.email' => __('auth.validation.email_format'),
            'email.max' => __('auth.validation.email_max'),
            'password.required' => __('auth.validation.password_required'),
            'password.max' => __('auth.validation.password_max'),
        ];
    }
}
