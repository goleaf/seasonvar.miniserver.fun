<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class VerifyEmailPage extends Component
{
    public string $email;

    public function mount(): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        $this->email = $user->email;
    }

    public function render(): View
    {
        return view('livewire.auth.verify-email-page')
            ->extends('layouts.app', [
                'title' => 'Подтверждение почты',
                'seo' => [
                    'title' => 'Подтверждение почты',
                    'description' => 'Подтверждение адреса электронной почты аккаунта.',
                    'robots' => 'noindex, nofollow',
                    'canonical' => route('verification.notice'),
                ],
            ])
            ->section('content');
    }
}
