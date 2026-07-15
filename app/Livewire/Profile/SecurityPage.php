<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Models\User;
use App\Services\Auth\AccountDateTimeFormatter;
use App\Services\Auth\AccountService;
use App\Services\Auth\AccountSettingsService;
use App\Services\Auth\BrowserSessionService;
use App\Services\Auth\MobileTokenService;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use LogicException;
use Throwable;

final class SecurityPage extends Component
{
    public string $currentPassword = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public ?string $passwordStatus = null;

    public ?string $deviceStatus = null;

    public ?string $sessionStatus = null;

    public ?string $securityError = null;

    public function updatePassword(
        AccountService $accounts,
        BrowserSessionService $sessions,
    ): void {
        $this->resetValidation();
        $this->securityError = null;

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
                $exception->errors()['current_password'][0] ?? __('settings.security_page.current_password_invalid'),
            );

            return;
        } catch (Throwable $exception) {
            $this->resetSensitiveProperties();
            $this->fail($exception);

            return;
        }

        $sessions->synchronizeCurrentPasswordHash($user);
        $this->resetSensitiveProperties();
        $this->passwordStatus = __('settings.security_page.password_changed');
    }

    public function revokeDevice(mixed $tokenId, MobileTokenService $tokens): void
    {
        $this->securityError = null;
        $tokenId = filter_var($tokenId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($tokenId === false) {
            abort(404);
        }

        try {
            $tokens->revoke($this->user(), $tokenId);
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

        $this->deviceStatus = __('settings.security_page.device_revoked');
    }

    public function revokeAllDevices(MobileTokenService $tokens): void
    {
        $this->securityError = null;

        try {
            $tokens->revokeAll($this->user());
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }
        $this->deviceStatus = __('settings.security_page.devices_revoked');
    }

    public function logoutOtherDevices(BrowserSessionService $sessions): void
    {
        $this->resetValidation();
        $this->securityError = null;

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
                $exception->errors()['current_password'][0] ?? __('settings.security_page.current_password_invalid'),
            );

            return;
        } catch (Throwable $exception) {
            $this->resetSensitiveProperties();
            $this->fail($exception);

            return;
        }

        $this->resetSensitiveProperties();
        $this->sessionStatus = __('settings.security_page.other_sessions_revoked');
    }

    public function revokeBrowserSession(string $sessionToken, BrowserSessionService $sessions): void
    {
        $this->resetValidation();
        $this->securityError = null;

        try {
            $this->validateCurrentPassword();
            $sessions->revoke($this->user(), $this->currentPassword, $sessionToken, Session::getId());
        } catch (ValidationException $exception) {
            $this->resetSensitiveProperties();
            $this->addError(
                isset($exception->errors()['session']) ? 'session' : 'currentPassword',
                $exception->errors()['session'][0]
                    ?? $exception->errors()['current_password'][0]
                    ?? __('settings.security_page.session_not_found'),
            );

            return;
        } catch (Throwable $exception) {
            $this->resetSensitiveProperties();
            $this->fail($exception);

            return;
        }

        $this->resetSensitiveProperties();
        $this->sessionStatus = __('settings.security_page.session_revoked');
    }

    public function deleteAccount(AccountService $accounts): void
    {
        $this->resetValidation();
        $this->securityError = null;

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
                $exception->errors()['password'][0] ?? __('settings.security_page.deletion_password_invalid'),
            );

            return;
        } catch (Throwable $exception) {
            $this->resetSensitiveProperties();
            $this->fail($exception);

            return;
        }

        $guard = Auth::guard('web');

        if (! $guard instanceof SessionGuard) {
            throw new LogicException('The web guard must use the session driver.');
        }

        $guard->logoutCurrentDevice();
        Session::invalidate();
        Session::regenerateToken();
        $this->redirectRoute('home');
    }

    public function render(
        BrowserSessionService $sessions,
        AccountSettingsService $settings,
        AccountDateTimeFormatter $dateTimes,
    ): View {
        $accountSettings = $settings->resolve($this->user());
        $devicesFailed = false;
        $sessionsFailed = false;

        try {
            $devices = $this->user()
                ->tokens()
                ->latest('id')
                ->get()
                ->map(fn ($token): array => [
                    'id' => (int) $token->getKey(),
                    'name' => (string) $token->name,
                    'last_used_at' => $token->last_used_at !== null
                        ? $dateTimes->value($token->last_used_at, $accountSettings->locale, $accountSettings->timezone)
                        : null,
                    'expires_at' => $token->expires_at !== null
                        ? $dateTimes->value($token->expires_at, $accountSettings->locale, $accountSettings->timezone)
                        : null,
                ]);
        } catch (Throwable $exception) {
            report($exception);
            $devices = collect();
            $devicesFailed = true;
        }

        try {
            $browserSessions = $sessions->summaries(
                $this->user(),
                Session::getId(),
                $accountSettings->locale,
                $accountSettings->timezone,
            );
        } catch (Throwable $exception) {
            report($exception);
            $browserSessions = collect();
            $sessionsFailed = true;
        }

        return view('livewire.profile.security-page', [
            'devices' => $devices,
            'browserSessions' => $browserSessions,
            'databaseSessionsAvailable' => config('session.driver') === 'database',
            'devicesFailed' => $devicesFailed,
            'sessionsFailed' => $sessionsFailed,
        ])
            ->extends('layouts.app', [
                'title' => __('settings.security_page.title'),
                'seo' => [
                    'title' => __('settings.security_page.title'),
                    'description' => __('settings.security_page.description'),
                    'robots' => 'noindex, nofollow',
                    'canonical' => route('profile.security'),
                    'social' => false,
                    'alternates' => [],
                    'jsonLd' => [],
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
            'currentPassword.required' => __('settings.security_page.validation.current_password'),
            'password.required' => __('settings.security_page.validation.new_password'),
            'password.min' => __('settings.security_page.validation.password_min'),
            'password.letters' => __('settings.security_page.validation.password_letters'),
            'password.mixed' => __('settings.security_page.validation.password_mixed'),
            'password.numbers' => __('settings.security_page.validation.password_numbers'),
            'password.symbols' => __('settings.security_page.validation.password_symbols'),
            'passwordConfirmation.required' => __('settings.security_page.validation.password_confirmation'),
            'passwordConfirmation.same' => __('settings.security_page.validation.password_confirmation_same'),
        ]);
    }

    private function validateCurrentPassword(): void
    {
        $this->validate([
            'currentPassword' => ['required', 'string', 'max:255'],
        ], [
            'currentPassword.required' => __('settings.security_page.validation.current_password'),
        ]);
    }

    private function resetSensitiveProperties(): void
    {
        $this->reset('currentPassword', 'password', 'passwordConfirmation');
    }

    private function fail(Throwable $exception): void
    {
        report($exception);
        $this->securityError = __('settings.security_page.action_failed');
    }

    private function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
