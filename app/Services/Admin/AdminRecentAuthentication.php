<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Exceptions\AdministrationAccessException;
use Illuminate\Contracts\Session\Session;

final readonly class AdminRecentAuthentication
{
    public function __construct(private Session $session) {}

    public function ensure(): void
    {
        $confirmedAt = $this->session->get('auth.password_confirmed_at');
        $timeout = max(60, (int) config('auth.password_timeout', 10800));

        if (! is_int($confirmedAt) || $confirmedAt < now()->timestamp - $timeout) {
            throw new AdministrationAccessException('administration.errors.recent_authentication_required');
        }
    }
}
