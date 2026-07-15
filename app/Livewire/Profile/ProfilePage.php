<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Models\User;
use App\Services\Auth\AccountService;
use App\Services\Catalog\UserLibrarySummaryQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Livewire\Component;

final class ProfilePage extends Component
{
    public string $name = '';

    public string $email = '';

    public bool $emailVerified = false;

    public string $createdAt = '';

    public ?string $status = null;

    public function mount(): void
    {
        $this->fillFromUser($this->user());
    }

    public function saveProfile(AccountService $accounts): void
    {
        $this->resetValidation();
        $this->name = Str::squish($this->name);
        $this->email = Str::lower(Str::squish($this->email));
        $user = $this->user();

        $this->withValidator(function (Validator $validator) use ($user): void {
            $validator->after(function (Validator $validator) use ($user): void {
                if ($this->email !== '' && User::query()
                    ->whereKeyNot($user->getKey())
                    ->whereRaw('lower(email) = ?', [$this->email])
                    ->exists()) {
                    $validator->errors()->add('email', 'Этот адрес электронной почты уже используется.');
                }
            });
        });

        $validated = $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email:rfc',
                'max:255',
                Rule::unique(User::class, 'email')->ignore($user),
            ],
        ], [
            'name.required' => 'Введите имя.',
            'name.min' => 'Имя должно содержать не менее 2 символов.',
            'name.max' => 'Имя не должно быть длиннее 120 символов.',
            'email.required' => 'Введите адрес электронной почты.',
            'email.email' => 'Введите корректный адрес электронной почты.',
            'email.unique' => 'Этот адрес электронной почты уже используется.',
        ]);

        $emailChanged = Str::lower($user->email) !== $validated['email'];
        $updated = $accounts->updateProfile($user, $validated);

        $this->fillFromUser($updated);
        $this->status = $emailChanged
            ? 'Профиль обновлён. Подтвердите новый адрес электронной почты.'
            : 'Профиль обновлён.';
    }

    public function render(UserLibrarySummaryQuery $summaries): View
    {
        return view('livewire.profile.profile-page', [
            'librarySummary' => $summaries->get($this->user()),
        ])
            ->extends('layouts.app', [
                'title' => 'Профиль',
                'seo' => [
                    'title' => 'Профиль',
                    'description' => 'Настройки пользовательского профиля.',
                    'robots' => 'noindex, nofollow',
                    'canonical' => route('profile.show'),
                ],
            ])
            ->section('content');
    }

    private function fillFromUser(User $user): void
    {
        $this->name = $user->name;
        $this->email = $user->email;
        $this->emailVerified = $user->hasVerifiedEmail();
        $this->createdAt = $user->created_at?->format('d.m.Y') ?? '';
    }

    private function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
