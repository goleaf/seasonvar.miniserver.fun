<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Livewire\Forms\Auth\ConfirmPasswordForm;
use App\Models\User;
use App\Services\Auth\WebAuthenticationRateLimiter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Component;

final class ConfirmPasswordPage extends Component
{
    private const MAX_ATTEMPTS = 5;

    public ConfirmPasswordForm $form;

    public function confirm(WebAuthenticationRateLimiter $rateLimiter): void
    {
        $password = $this->form->validatedPassword();
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        $rateKey = $rateLimiter->loginKey($user->email, request()->ip());

        if ($rateLimiter->tooManyAttempts($rateKey, self::MAX_ATTEMPTS)) {
            $this->form->addError('password', 'Слишком много попыток. Повторите попытку позже.');

            return;
        }

        $rateLimiter->hit($rateKey);

        if (! Hash::check($password, $user->password)) {
            $this->form->addError('password', 'Не удалось подтвердить пароль.');

            return;
        }

        $rateLimiter->clear($rateKey);
        $this->form->reset();
        Session::passwordConfirmed();
        $this->redirectIntended(route('library.index'));
    }

    public function render(): View
    {
        return view('livewire.auth.confirm-password-page')
            ->extends('layouts.app', [
                'title' => 'Подтверждение пароля',
                'seo' => [
                    'title' => 'Подтверждение пароля',
                    'description' => 'Подтверждение пароля перед защищённым действием.',
                    'robots' => 'noindex, nofollow',
                    'canonical' => route('password.confirm'),
                ],
            ])
            ->section('content');
    }
}
