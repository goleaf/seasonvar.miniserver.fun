<?php

declare(strict_types=1);

namespace App\Livewire\Forms\Auth;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;
use Livewire\Form;

final class RegistrationForm extends Form
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    /** @return array{name: string, email: string, password: string} */
    public function validatedData(): array
    {
        $this->name = Str::squish($this->name);
        $this->email = Str::lower(Str::squish($this->email));

        $this->withValidator(function (Validator $validator): void {
            $validator->after(function (Validator $validator): void {
                if ($this->email !== '' && User::query()->whereRaw('lower(email) = ?', [$this->email])->exists()) {
                    $validator->errors()->add('email', 'Этот адрес электронной почты уже используется.');
                }
            });
        });

        $validated = $this->validate();

        return [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ];
    }

    /** @return array<string, list<mixed>> */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'lowercase', 'email:rfc', 'max:255', Rule::unique(User::class, 'email')],
            'password' => ['required', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
            'passwordConfirmation' => ['required', 'same:password'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'name.required' => 'Введите имя.',
            'name.min' => 'Имя должно содержать не менее 2 символов.',
            'name.max' => 'Имя не должно быть длиннее 120 символов.',
            'email.required' => 'Введите адрес электронной почты.',
            'email.email' => 'Введите корректный адрес электронной почты.',
            'email.unique' => 'Этот адрес электронной почты уже используется.',
            'password.required' => 'Введите пароль.',
            'passwordConfirmation.required' => 'Повторите пароль.',
            'passwordConfirmation.same' => 'Подтверждение пароля не совпадает.',
        ];
    }
}
