<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Models\User;
use App\Services\Auth\AccountService;
use App\Services\Auth\BrowserSessionService;
use App\Services\Auth\MobileTokenService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

final class SecurityPage extends Component
{
    public string $currentPassword = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public ?string $passwordStatus = null;

    public ?string $deviceStatus = null;

    public ?string $sessionStatus = null;

    public function updatePassword(
        AccountService $accounts,
        BrowserSessionService $sessions,
    ): void {
        $this->resetValidation();

        try {
            $this->validatePasswordChange();
        } catch (ValidationException $exception) {
            $this->resetSensitiveProperties();

            throw $exception;
        }

        $user = $this->user();

        try {
            $accounts->updatePassword($user, $this->currentPassword, $this->password, null);
        } catch (ValidationException $exception) {
            $this->resetSensitiveProperties();
            $this->addError(
                'currentPassword',
                $exception->errors()['current_password'][0] ?? 'Текущий пароль указан неверно.',
            );

            return;
        }

        $sessions->synchronizeCurrentPasswordHash($user);
        $this->resetSensitiveProperties();
        $this->passwordStatus = 'Пароль успешно изменён.';
    }

    public function revokeDevice(mixed $tokenId, MobileTokenService $tokens): void
    {
        $tokenId = filter_var($tokenId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($tokenId === false) {
            abort(404);
        }

        try {
            $tokens->revoke($this->user(), $tokenId);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        $this->deviceStatus = 'Устройство отключено.';
    }

    public function revokeAllDevices(MobileTokenService $tokens): void
    {
        $tokens->revokeAll($this->user());
        $this->deviceStatus = 'Все устройства API отключены.';
    }

    public function logoutOtherDevices(BrowserSessionService $sessions): void
    {
        $this->resetValidation();

        try {
            $this->validateCurrentPassword();
        } catch (ValidationException $exception) {
            $this->resetSensitiveProperties();

            throw $exception;
        }

        try {
            $sessions->logoutOtherDevices(
                $this->user(),
                $this->currentPassword,
                Session::getId(),
            );
        } catch (ValidationException $exception) {
            $this->resetSensitiveProperties();
            $this->addError(
                'currentPassword',
                $exception->errors()['current_password'][0] ?? 'Текущий пароль указан неверно.',
            );

            return;
        }

        $this->resetSensitiveProperties();
        $this->sessionStatus = 'Другие браузерные сессии завершены.';
    }

    public function deleteAccount(AccountService $accounts): void
    {
        $this->resetValidation();

        try {
            $this->validateCurrentPassword();
        } catch (ValidationException $exception) {
            $this->resetSensitiveProperties();

            throw $exception;
        }

        $user = $this->user();

        try {
            $accounts->delete($user, $this->currentPassword);
        } catch (ValidationException $exception) {
            $this->resetSensitiveProperties();
            $this->addError(
                'currentPassword',
                $exception->errors()['password'][0] ?? 'Не удалось подтвердить пароль.',
            );

            return;
        }

        Auth::guard('web')->logoutCurrentDevice();
        Session::invalidate();
        Session::regenerateToken();
        $this->redirectRoute('home');
    }

    public function render(): View
    {
        $devices = $this->user()
            ->tokens()
            ->latest('id')
            ->get()
            ->map(fn ($token): array => [
                'id' => (int) $token->getKey(),
                'name' => (string) $token->name,
                'last_used_at' => $token->last_used_at?->format('d.m.Y H:i'),
                'expires_at' => $token->expires_at?->format('d.m.Y H:i'),
            ]);

        return view('livewire.profile.security-page', ['devices' => $devices])
            ->extends('layouts.app', [
                'title' => 'Безопасность',
                'seo' => [
                    'title' => 'Безопасность',
                    'description' => 'Пароль, браузерные сессии и устройства API.',
                    'robots' => 'noindex, nofollow',
                    'canonical' => route('profile.security'),
                ],
            ])
            ->section('content');
    }

    private function validatePasswordChange(): void
    {
        $this->validate([
            'currentPassword' => ['required', 'string', 'max:255'],
            'password' => ['required', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
            'passwordConfirmation' => ['required', 'same:password'],
        ], [
            'currentPassword.required' => 'Введите текущий пароль.',
            'password.required' => 'Введите новый пароль.',
            'password.min' => 'Пароль должен содержать не менее 12 символов.',
            'password.letters' => 'Пароль должен содержать буквы.',
            'password.mixed' => 'Пароль должен содержать строчные и заглавные буквы.',
            'password.numbers' => 'Пароль должен содержать цифры.',
            'password.symbols' => 'Пароль должен содержать специальный символ.',
            'passwordConfirmation.required' => 'Повторите новый пароль.',
            'passwordConfirmation.same' => 'Подтверждение пароля не совпадает.',
        ]);
    }

    private function validateCurrentPassword(): void
    {
        $this->validate([
            'currentPassword' => ['required', 'string', 'max:255'],
        ], [
            'currentPassword.required' => 'Введите текущий пароль.',
        ]);
    }

    private function resetSensitiveProperties(): void
    {
        $this->reset('currentPassword', 'password', 'passwordConfirmation');
    }

    private function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
