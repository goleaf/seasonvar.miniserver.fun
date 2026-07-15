<?php

declare(strict_types=1);

namespace App\ValueObjects;

use DateTimeZone;
use InvalidArgumentException;

final readonly class AccountTimezone
{
    private function __construct(public string $value) {}

    public static function from(string $value): self
    {
        if (! in_array($value, DateTimeZone::listIdentifiers(), true) && $value !== 'UTC') {
            throw new InvalidArgumentException('Unsupported IANA timezone.');
        }

        return new self($value);
    }

    /** @return list<string> */
    public static function identifiers(): array
    {
        return array_values(array_unique(['UTC', ...DateTimeZone::listIdentifiers()]));
    }
}
