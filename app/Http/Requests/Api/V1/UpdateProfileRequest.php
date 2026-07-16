<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use App\ValueObjects\NormalizedEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120', 'not_regex:/[\p{Cc}\p{Cs}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'lowercase',
                'email:rfc',
                'max:255',
                Rule::unique(User::class, 'email')->ignore($this->user()),
            ],
            'current_password' => ['nullable', 'string', 'max:255'],
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
            'current_password.max' => __('auth.validation.password_max'),
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasAny(['name', 'email'])) {
                    $validator->errors()->add('profile', __('auth.validation.profile_required'));

                    return;
                }

                $email = $this->string('email')->toString();
                $userId = (int) $this->user()->getAuthIdentifier();

                if ($email !== '' && User::query()
                    ->whereKeyNot($userId)
                    ->whereEmailIdentity($email)
                    ->exists()) {
                    $validator->errors()->add('email', __('auth.validation.email_unique'));
                }
            },
        ];
    }

    /** @return array{name?: string, email?: string} */
    public function profileData(): array
    {
        $data = [];

        if ($this->has('name')) {
            $data['name'] = $this->string('name')->toString();
        }

        if ($this->has('email')) {
            $data['email'] = $this->string('email')->toString();
        }

        return $data;
    }

    public function currentPassword(): ?string
    {
        $password = $this->input('current_password');

        return is_string($password) && $password !== '' ? $password : null;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        $name = $this->input('name');
        $email = $this->input('email');

        if (is_string($name)) {
            $normalized['name'] = Str::squish($name);
        }

        if (is_string($email)) {
            $normalized['email'] = NormalizedEmail::value($email);
        }

        $this->merge($normalized);
    }
}
