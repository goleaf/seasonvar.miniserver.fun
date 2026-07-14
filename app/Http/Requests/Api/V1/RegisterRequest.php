<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\User;
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
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'lowercase', 'email:rfc', 'max:255', Rule::unique(User::class, 'email')],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
            'device_name' => ['required', 'string', 'min:2', 'max:120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Введите имя.',
            'name.min' => 'Имя должно содержать не менее 2 символов.',
            'name.max' => 'Имя не должно быть длиннее 120 символов.',
            'email.required' => 'Введите адрес электронной почты.',
            'email.email' => 'Введите корректный адрес электронной почты.',
            'email.unique' => 'Этот адрес электронной почты уже используется.',
            'password.confirmed' => 'Подтверждение пароля не совпадает.',
            'device_name.required' => 'Укажите название устройства.',
            'device_name.min' => 'Название устройства должно содержать не менее 2 символов.',
            'device_name.max' => 'Название устройства не должно быть длиннее 120 символов.',
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $email = $this->string('email')->toString();

                if ($email !== '' && User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
                    $validator->errors()->add('email', 'Этот адрес электронной почты уже используется.');
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
            $normalized['email'] = Str::lower(Str::squish($email));
        }

        $this->merge($normalized);
    }
}
