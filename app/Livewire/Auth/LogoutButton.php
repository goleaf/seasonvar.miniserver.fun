<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Services\Auth\WebAuthenticationService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class LogoutButton extends Component
{
    public function logout(WebAuthenticationService $authentication): void
    {
        $authentication->logout();

        $this->redirectRoute('home');
    }

    public function render(): View
    {
        return view('livewire.auth.logout-button');
    }
}
