<?php

declare(strict_types=1);

namespace App\Services\ReleaseCalendar;

final class ReleaseCalendarFeedToken
{
    public const LENGTH = 64;

    public function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
