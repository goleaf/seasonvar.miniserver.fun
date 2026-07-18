<?php

declare(strict_types=1);

namespace App\ValueObjects;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class PremiumCouponCode
{
    public function __construct(public string $value)
    {
        if (preg_match('/\A[A-Z0-9](?:[A-Z0-9-]{6,62}[A-Z0-9])\z/', $value) !== 1) {
            throw new InvalidArgumentException('Промокод имеет недопустимый формат.');
        }
    }

    public static function from(string $value): self
    {
        $normalized = Str::of($value)->trim()->upper()->replaceMatches('/\s+/', '')->value();

        return new self($normalized);
    }

    public static function generate(): self
    {
        return new self('SV-'.Str::upper(Str::random(16)));
    }

    public function hash(): string
    {
        return hash_hmac('sha256', $this->value, (string) config('app.key'));
    }

    public function hint(): string
    {
        return '••••-'.Str::substr($this->value, -4);
    }
}
