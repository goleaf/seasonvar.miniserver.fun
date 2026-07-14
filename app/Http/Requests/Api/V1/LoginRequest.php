<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['required', 'string', 'min:2', 'max:120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.required' => 'Введите адрес электронной почты.',
            'email.email' => 'Введите корректный адрес электронной почты.',
            'password.required' => 'Введите пароль.',
            'device_name.required' => 'Укажите название устройства.',
            'device_name.min' => 'Название устройства должно содержать не менее 2 символов.',
            'device_name.max' => 'Название устройства не должно быть длиннее 120 символов.',
        ];
    }

    public function email(): string
    {
        return $this->string('email')->toString();
    }

    public function password(): string
    {
        return $this->string('password')->toString();
    }

    public function deviceName(): string
    {
        return $this->string('device_name')->toString();
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        $email = $this->input('email');
        $deviceName = $this->input('device_name');

        if (is_string($email)) {
            $normalized['email'] = Str::lower(Str::squish($email));
        }

        if (is_string($deviceName)) {
            $normalized['device_name'] = Str::squish($deviceName);
        }

        $this->merge($normalized);
    }
}
