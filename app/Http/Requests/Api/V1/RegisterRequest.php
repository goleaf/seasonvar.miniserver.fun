<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use App\ValueObjects\NormalizedEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

final class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120', 'not_regex:/[\p{Cc}\p{Cs}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u'],
            'email' => ['required', 'string', 'lowercase', 'email:rfc', 'max:255', Rule::unique(User::class, 'email')],
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::defaults()],
            'password_confirmation' => ['required', 'string', 'max:255'],
            'device_name' => ['required', 'string', 'min:2', 'max:120', 'not_regex:/[\p{Cc}\p{Cs}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
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
            'password.confirmed' => __('auth.validation.password_confirmation_same'),
            'password.max' => __('auth.validation.password_max'),
            'password.min' => __('auth.validation.password_min'),
            'password.letters' => __('auth.validation.password_letters'),
            'password.mixed' => __('auth.validation.password_mixed'),
            'password.numbers' => __('auth.validation.password_numbers'),
            'password.symbols' => __('auth.validation.password_symbols'),
            'password_confirmation.required' => __('auth.validation.password_confirmation_required'),
            'password_confirmation.max' => __('auth.validation.password_max'),
            'device_name.required' => __('auth.validation.device_name_required'),
            'device_name.min' => __('auth.validation.device_name_min'),
            'device_name.max' => __('auth.validation.device_name_max'),
            'device_name.not_regex' => __('auth.validation.device_name_controls'),
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $email = $this->string('email')->toString();

                if ($email !== '' && User::query()->whereEmailIdentity($email)->exists()) {
                    $validator->errors()->add('email', __('auth.validation.email_unique'));
                }
            },
        ];
    }

    /** @return array{name: string, email: string, password: string, device_name: string} */
    public function registrationData(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'email' => $this->string('email')->toString(),
            'password' => $this->string('password')->toString(),
            'device_name' => $this->string('device_name')->toString(),
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['name', 'device_name'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $normalized[$field] = Str::squish($value);
            }
        }

        $email = $this->input('email');

        if (is_string($email)) {
            $normalized['email'] = NormalizedEmail::value($email);
        }

        $this->merge($normalized);
    }
}
