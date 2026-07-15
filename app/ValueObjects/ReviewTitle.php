<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Exceptions\Reviews\ReviewActionException;
use App\Support\UserPlainText;
use Illuminate\Support\Str;

final readonly class ReviewTitle
{
    private function __construct(public string $value) {}

    public static function from(mixed $input): self
    {
        $raw = (string) $input;

        if (! mb_check_encoding($raw, 'UTF-8')
            || preg_match('/[\p{Cc}\p{Cs}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $raw) === 1) {
            throw new ReviewActionException('reviews.errors.invalid_characters');
        }

        $value = UserPlainText::name($input);
        $minimum = max(1, (int) config('reviews.title.minimum_length', 5));
        $maximum = max($minimum, (int) config('reviews.title.maximum_length', 120));

        if ($value === '') {
            throw new ReviewActionException('reviews.errors.title_required');
        }

        if (Str::length($value) < $minimum) {
            throw new ReviewActionException('reviews.errors.title_too_short', ['minimum' => $minimum]);
        }

        if (Str::length($value) > $maximum) {
            throw new ReviewActionException('reviews.errors.title_too_long', ['maximum' => $maximum]);
        }

        $generic = collect(config('reviews.title.generic_values', []))
            ->filter('is_string')
            ->map(fn (string $item): string => Str::lower(UserPlainText::name($item)))
            ->contains(Str::lower($value));

        if ($generic) {
            throw new ReviewActionException('reviews.errors.title_too_generic');
        }

        return new self($value);
    }
}
