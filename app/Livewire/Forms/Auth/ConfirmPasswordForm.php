<?php

declare(strict_types=1);

namespace App\Livewire\Forms\Auth;

use Livewire\Form;

final class ConfirmPasswordForm extends Form
{
    public string $password = '';

    public function validatedPassword(): string
    {
        $validated = $this->validate();

        return $validated['password'];
    }

    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'password.required' => __('auth.validation.current_password_required'),
            'password.max' => __('auth.validation.password_max'),
        ];
    }
}
