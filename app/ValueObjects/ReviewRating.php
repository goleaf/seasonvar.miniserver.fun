<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Exceptions\Reviews\ReviewActionException;

final readonly class ReviewRating
{
    private function __construct(
        public ?int $value,
        public int $minimum,
        public int $maximum,
    ) {}

    public static function from(mixed $input): self
    {
        $minimum = max(1, min(255, (int) config('catalog.user_rating.minimum', 1)));
        $maximum = max($minimum, min(255, (int) config('catalog.user_rating.maximum', 10)));

        if ($input === null || $input === '') {
            return new self(null, $minimum, $maximum);
        }

        if (is_int($input)) {
            $value = $input;
        } elseif (is_string($input) && preg_match('/^-?\d+$/D', $input) === 1) {
            $value = (int) $input;
        } else {
            throw new ReviewActionException('reviews.errors.invalid_rating', [
                'minimum' => $minimum,
                'maximum' => $maximum,
            ]);
        }

        if ($value < $minimum || $value > $maximum) {
            throw new ReviewActionException('reviews.errors.invalid_rating', [
                'minimum' => $minimum,
                'maximum' => $maximum,
            ]);
        }

        return new self($value, $minimum, $maximum);
    }
}
