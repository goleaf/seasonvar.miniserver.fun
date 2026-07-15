<?php

declare(strict_types=1);

namespace App\Livewire\Forms\Auth;

use Illuminate\Support\Str;
use Livewire\Form;

final class ForgotPasswordForm extends Form
{
    public string $email = '';

    public function validatedEmail(): string
    {
        $this->email = Str::lower(Str::squish($this->email));
        $validated = $this->validate();

        return $validated['email'];
    }

    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'email.required' => 'Введите адрес электронной почты.',
            'email.email' => 'Введите корректный адрес электронной почты.',
        ];
    }
}
