<?php

declare(strict_types=1);

namespace App\Livewire\Forms\Auth;

use Illuminate\Support\Str;
use Livewire\Form;

final class LoginForm extends Form
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    /** @return array{email: string, password: string, remember: bool} */
    public function validatedData(): array
    {
        $this->email = Str::lower(Str::squish($this->email));
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
            'email.required' => 'Введите адрес электронной почты.',
            'email.email' => 'Введите корректный адрес электронной почты.',
            'password.required' => 'Введите пароль.',
        ];
    }
}
