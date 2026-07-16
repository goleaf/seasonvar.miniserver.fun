<?php

declare(strict_types=1);

namespace App\Livewire\Forms\Auth;

use App\ValueObjects\NormalizedEmail;
use Livewire\Form;

final class ForgotPasswordForm extends Form
{
    public string $email = '';

    public function validatedEmail(): string
    {
        $this->email = NormalizedEmail::value($this->email);
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
            'email.required' => __('auth.validation.email_required'),
            'email.email' => __('auth.validation.email_format'),
            'email.max' => __('auth.validation.email_max'),
        ];
    }
}
