<?php

declare(strict_types=1);

namespace App\Livewire\Forms\Auth;

use App\Models\User;
use App\ValueObjects\NormalizedEmail;
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
        $this->email = NormalizedEmail::value($this->email);

        $this->withValidator(function (Validator $validator): void {
            $validator->after(function (Validator $validator): void {
                if ($this->email !== '' && User::query()->whereEmailIdentity($this->email)->exists()) {
                    $validator->errors()->add('email', __('auth.validation.email_unique'));
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
            'name' => ['required', 'string', 'min:2', 'max:120', 'not_regex:/[\p{Cc}\p{Cs}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u'],
            'email' => ['required', 'string', 'lowercase', 'email:rfc', 'max:255', Rule::unique(User::class, 'email')],
            'password' => ['required', 'string', 'max:255', Password::defaults()],
            'passwordConfirmation' => ['required', 'string', 'max:255', 'same:password'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'name.required' => __('auth.validation.name_required'),
            'name.min' => __('auth.validation.name_min'),
            'name.max' => __('auth.validation.name_max'),
            'name.not_regex' => __('auth.validation.name_controls'),
            'email.required' => __('auth.validation.email_required'),
            'email.email' => __('auth.validation.email_format'),
            'email.max' => __('auth.validation.email_max'),
            'email.unique' => __('auth.validation.email_unique'),
            'password.required' => __('auth.validation.password_required'),
            'password.max' => __('auth.validation.password_max'),
            'password.min' => __('auth.validation.password_min'),
            'password.letters' => __('auth.validation.password_letters'),
            'password.mixed' => __('auth.validation.password_mixed'),
            'password.numbers' => __('auth.validation.password_numbers'),
            'password.symbols' => __('auth.validation.password_symbols'),
            'passwordConfirmation.required' => __('auth.validation.password_confirmation_required'),
            'passwordConfirmation.max' => __('auth.validation.password_max'),
            'passwordConfirmation.same' => __('auth.validation.password_confirmation_same'),
        ];
    }
}
