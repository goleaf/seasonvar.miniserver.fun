<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class CatalogFilterSlug implements ValidationRule
{
    public const MAX_LENGTH = 120;

    private const PATTERN = '/^[a-z0-9][a-z0-9-]*$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::normalize($value) === null) {
            $fail('Поле :attribute должно быть slug: строчные латинские буквы, цифры и дефисы, до '.self::MAX_LENGTH.' символов.');
        }
    }

    public static function normalize(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        return self::isValid($value) ? $value : null;
    }

    public static function isValid(string $value): bool
    {
        return mb_strlen($value) <= self::MAX_LENGTH
            && preg_match(self::PATTERN, $value) === 1;
    }
}
