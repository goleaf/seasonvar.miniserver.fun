<?php

declare(strict_types=1);

namespace App\ValueObjects;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ProfileUsername
{
    public string $value;

    public function __construct(string $value)
    {
        $normalized = self::normalize($value);

        if (! self::isValid($normalized)) {
            throw new InvalidArgumentException('Invalid profile username.');
        }

        $this->value = $normalized;
    }

    public static function normalize(string $value): string
    {
        return Str::of($value)->trim()->lower()->toString();
    }

    public static function isValid(string $value): bool
    {
        $minimum = max(1, (int) config('user-profiles.username.minimum_length', 3));
        $maximum = max($minimum, (int) config('user-profiles.username.maximum_length', 32));

        return strlen($value) >= $minimum
            && strlen($value) <= $maximum
            && preg_match('/^[a-z0-9]+(?:_[a-z0-9]+)*$/', $value) === 1
            && ! in_array($value, (array) config('user-profiles.username.reserved', []), true);
    }

    public static function generated(string $displayName, string $stableIdentity): string
    {
        $candidate = Str::of(Str::ascii($displayName))
            ->lower()
            ->replaceMatches('/[^a-z0-9_]+/', '_')
            ->trim('_')
            ->replaceMatches('/_+/', '_')
            ->limit(24, '')
            ->toString();

        return self::isValid($candidate)
            ? $candidate
            : 'user_'.substr(str_replace('-', '', $stableIdentity), 0, 12);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
