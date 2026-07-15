<?php

declare(strict_types=1);

namespace App\Livewire\Forms\Auth;

use Illuminate\Support\Str;
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
        $this->email = Str::lower(Str::squish($this->email));
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
            'token' => ['required', 'string'],
            'password' => ['required', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
            'passwordConfirmation' => ['required', 'same:password'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'email.required' => 'Введите адрес электронной почты.',
            'email.email' => 'Введите корректный адрес электронной почты.',
            'token.required' => 'Ссылка для восстановления недействительна.',
            'password.required' => 'Введите новый пароль.',
            'password.min' => 'Пароль должен содержать не менее 12 символов.',
            'password.letters' => 'Пароль должен содержать буквы.',
            'password.mixed' => 'Пароль должен содержать строчные и заглавные буквы.',
            'password.numbers' => 'Пароль должен содержать цифры.',
            'password.symbols' => 'Пароль должен содержать специальный символ.',
            'passwordConfirmation.required' => 'Повторите новый пароль.',
            'passwordConfirmation.same' => 'Подтверждение пароля не совпадает.',
        ];
    }
}
