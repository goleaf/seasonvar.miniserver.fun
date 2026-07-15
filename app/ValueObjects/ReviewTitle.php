<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Exceptions\Reviews\ReviewActionException;
use App\Support\UserPlainText;
use Illuminate\Support\Str;
use Stringable;

final readonly class ReviewTitle
{
    private function __construct(public string $value) {}

    public static function from(mixed $input): self
    {
        if (! is_string($input)
            && ! is_int($input)
            && ! is_float($input)
            && ! $input instanceof Stringable) {
            throw new ReviewActionException('reviews.errors.title_required');
        }

        $raw = (string) $input;
        $minimum = max(1, (int) config('reviews.title.minimum_length', 5));
        $maximum = max($minimum, (int) config('reviews.title.maximum_length', 120));

        if (strlen($raw) > max(4_096, $maximum * 8)) {
            throw new ReviewActionException('reviews.errors.title_too_long', ['maximum' => $maximum]);
        }

        if (! mb_check_encoding($raw, 'UTF-8')
            || preg_match('/[\p{Cc}\p{Cs}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $raw) === 1) {
            throw new ReviewActionException('reviews.errors.invalid_characters');
        }

        $value = UserPlainText::name($input);

        if ($value === '') {
            throw new ReviewActionException('reviews.errors.title_required');
        }

        if (Str::length($value) < $minimum) {
            throw new ReviewActionException('reviews.errors.title_too_short', ['minimum' => $minimum]);
        }

        if (Str::length($value) > $maximum) {
            throw new ReviewActionException('reviews.errors.title_too_long', ['maximum' => $maximum]);
        }

        $configuredGenericValues = config('reviews.title.generic_values', []);
        $genericValues = is_array($configuredGenericValues) ? $configuredGenericValues : [];
        $generic = collect($genericValues)
            ->filter('is_string')
            ->map(fn (string $item): string => Str::lower(UserPlainText::name($item)))
            ->contains(Str::lower($value));

        if ($generic) {
            throw new ReviewActionException('reviews.errors.title_too_generic');
        }

        return new self($value);
    }
}
