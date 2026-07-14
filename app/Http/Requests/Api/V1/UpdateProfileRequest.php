<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\User;
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
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'lowercase',
                'email:rfc',
                'max:255',
                Rule::unique(User::class, 'email')->ignore($this->user()),
            ],
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
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasAny(['name', 'email'])) {
                    $validator->errors()->add('profile', 'Укажите имя или адрес электронной почты.');

                    return;
                }

                $email = $this->string('email')->toString();
                $userId = (int) $this->user()->getAuthIdentifier();

                if ($email !== '' && User::query()
                    ->whereKeyNot($userId)
                    ->whereRaw('lower(email) = ?', [$email])
                    ->exists()) {
                    $validator->errors()->add('email', 'Этот адрес электронной почты уже используется.');
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

    protected function prepareForValidation(): void
    {
        $normalized = [];
        $name = $this->input('name');
        $email = $this->input('email');

        if (is_string($name)) {
            $normalized['name'] = Str::squish($name);
        }

        if (is_string($email)) {
            $normalized['email'] = Str::lower(Str::squish($email));
        }

        $this->merge($normalized);
    }
}
