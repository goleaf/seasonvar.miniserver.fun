<?php

declare(strict_types=1);

namespace App\Services\Auth;

final class RegistrationAvailability
{
    public function enabled(): bool
    {
        return (bool) config('authentication.registration.enabled', true);
    }

    public function ensureEnabled(): void
    {
        abort_unless($this->enabled(), 404);
    }
}
