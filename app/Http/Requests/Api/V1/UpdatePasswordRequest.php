<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'current_password' => ['bail', 'required', 'string', 'max:255', 'current_password'],
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::defaults()],
            'password_confirmation' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.required' => __('auth.validation.current_password_required'),
            'current_password.max' => __('auth.validation.password_max'),
            'current_password.current_password' => __('auth.validation.current_password_invalid'),
            'password.required' => __('auth.validation.new_password_required'),
            'password.confirmed' => __('auth.validation.password_confirmation_same'),
            'password.max' => __('auth.validation.password_max'),
            'password.min' => __('auth.validation.password_min'),
            'password.letters' => __('auth.validation.password_letters'),
            'password.mixed' => __('auth.validation.password_mixed'),
            'password.numbers' => __('auth.validation.password_numbers'),
            'password.symbols' => __('auth.validation.password_symbols'),
            'password_confirmation.required' => __('auth.validation.new_password_confirmation_required'),
            'password_confirmation.max' => __('auth.validation.password_max'),
        ];
    }

    public function currentPassword(): string
    {
        return $this->string('current_password')->toString();
    }

    public function newPassword(): string
    {
        return $this->string('password')->toString();
    }
}
