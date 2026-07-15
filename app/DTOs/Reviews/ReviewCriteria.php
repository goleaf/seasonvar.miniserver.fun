<?php

declare(strict_types=1);

namespace App\DTOs\Reviews;

use App\Enums\ReviewSort;
use App\ValueObjects\ReviewRating;

final readonly class ReviewCriteria
{
    public function __construct(
        public ReviewSort $sort,
        public ?int $rating,
        public ?bool $containsSpoiler,
        public ?bool $verifiedWatching,
    ) {}

    public static function from(
        mixed $sort,
        mixed $rating,
        mixed $spoiler,
        mixed $verified,
    ): self {
        $sort = is_string($sort) ? ReviewSort::tryFrom($sort) : null;
        $rating = ReviewRating::from($rating)->value;

        return new self(
            sort: $sort ?? ReviewSort::Newest,
            rating: $rating,
            containsSpoiler: self::nullableBoolean($spoiler, 'contains', 'spoiler_free'),
            verifiedWatching: self::nullableBoolean($verified, 'verified', 'unverified'),
        );
    }

    private static function nullableBoolean(mixed $value, string $truthy, string $falsy): ?bool
    {
        return match ($value) {
            $truthy => true,
            $falsy => false,
            default => null,
        };
    }
}
