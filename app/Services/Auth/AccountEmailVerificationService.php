<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\AuthenticationEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Events\Verified;

final class AccountEmailVerificationService
{
    public function __construct(private readonly AuthenticationAuditService $audit) {}

    public function verify(int $userId, string $hash): User
    {
        $user = User::query()->findOrFail($userId);

        if (! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            throw new AuthorizationException;
        }

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
            $this->audit->record(AuthenticationEvent::VerificationCompleted, $user, $user->email);
        }

        return $user;
    }
}
