<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\ValueObjects\NormalizedEmail;
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
            'device_name' => ['required', 'string', 'min:2', 'max:120', 'not_regex:/[\p{Cc}\p{Cs}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.required' => __('auth.validation.email_required'),
            'email.email' => __('auth.validation.email_format'),
            'email.max' => __('auth.validation.email_max'),
            'password.required' => __('auth.validation.password_required'),
            'password.max' => __('auth.validation.password_max'),
            'device_name.required' => __('auth.validation.device_name_required'),
            'device_name.min' => __('auth.validation.device_name_min'),
            'device_name.max' => __('auth.validation.device_name_max'),
            'device_name.not_regex' => __('auth.validation.device_name_controls'),
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
            $normalized['email'] = NormalizedEmail::value($email);
        }

        if (is_string($deviceName)) {
            $normalized['device_name'] = Str::squish($deviceName);
        }

        $this->merge($normalized);
    }
}
