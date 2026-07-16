<?php

declare(strict_types=1);

namespace App\ValueObjects;

use Illuminate\Support\Str;

final readonly class NormalizedEmail
{
    private function __construct(public string $value) {}

    public static function from(string $email): self
    {
        $trimmed = preg_replace('/^\s+|\s+$/u', '', $email) ?? trim($email);

        return new self(Str::lower($trimmed));
    }

    public static function value(string $email): string
    {
        return self::from($email)->value;
    }
}
