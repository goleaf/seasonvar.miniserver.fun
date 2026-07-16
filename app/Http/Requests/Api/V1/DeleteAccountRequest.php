<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Validator;

final class DeleteAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'password.required' => __('auth.validation.delete_password_invalid'),
            'password.max' => __('auth.validation.password_max'),
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->has('password')
                    && ! Hash::check($this->password(), $this->user()->password)) {
                    $validator->errors()->add('password', __('auth.validation.delete_password_invalid'));
                }
            },
        ];
    }

    public function password(): string
    {
        return $this->string('password')->toString();
    }
}
