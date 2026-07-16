<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\ValueObjects\NormalizedEmail;

final class AuthenticationFingerprint
{
    public function email(string $email): string
    {
        return $this->hash('email|'.NormalizedEmail::value($email));
    }

    public function network(?string $ipAddress): string
    {
        return $this->hash('network|'.($ipAddress ?: 'unknown'));
    }

    public function opaque(string $scope, string $value): string
    {
        return $this->hash($scope.'|'.$value);
    }

    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
