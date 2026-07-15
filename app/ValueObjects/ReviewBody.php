<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Exceptions\Reviews\ReviewActionException;
use App\Support\UserPlainText;
use Illuminate\Support\Str;
use Stringable;

final readonly class ReviewBody
{
    private function __construct(
        public string $value,
        public string $normalizedHash,
        public int $linkCount,
    ) {}

    public static function from(mixed $input): self
    {
        if (! is_string($input)
            && ! is_int($input)
            && ! is_float($input)
            && ! $input instanceof Stringable) {
            throw new ReviewActionException('reviews.errors.body_required');
        }

        $raw = (string) $input;
        $minimum = max(1, (int) config('reviews.body.minimum_length', 100));
        $maximum = max($minimum, (int) config('reviews.body.maximum_length', 12_000));

        if (strlen($raw) > max(64_000, $maximum * 8)) {
            throw new ReviewActionException('reviews.errors.body_too_long', ['maximum' => $maximum]);
        }

        if (! mb_check_encoding($raw, 'UTF-8')
            || preg_match('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $raw) === 1) {
            throw new ReviewActionException('reviews.errors.invalid_characters');
        }

        $value = UserPlainText::description($input) ?? '';

        if ($value === '') {
            throw new ReviewActionException('reviews.errors.body_required');
        }

        if (Str::length($value) < $minimum) {
            throw new ReviewActionException('reviews.errors.body_too_short', ['minimum' => $minimum]);
        }

        if (Str::length($value) > $maximum) {
            throw new ReviewActionException('reviews.errors.body_too_long', ['maximum' => $maximum]);
        }

        $lineCount = substr_count($value, "\n") + 1;
        $maximumLines = max(1, (int) config('reviews.body.maximum_lines', 80));

        if ($lineCount > $maximumLines) {
            throw new ReviewActionException('reviews.errors.too_many_lines', ['maximum' => $maximumLines]);
        }

        if (preg_match('/(?:^|[\s\x{0022}\x{0027}(<])(?:javascript|vbscript|data)\s*:/iu', $value) === 1) {
            throw new ReviewActionException('reviews.errors.dangerous_link');
        }

        $linkCount = preg_match_all('/\b(?:https?:\/\/|www\.)[^\s<>{}\[\]]+/iu', $value) ?: 0;
        $maximumLinks = max(0, (int) config('reviews.body.maximum_links', 2));

        if ($linkCount > $maximumLinks) {
            throw new ReviewActionException('reviews.errors.too_many_links', ['maximum' => $maximumLinks]);
        }

        $maximumRepeated = max(3, (int) config('reviews.body.maximum_repeated_characters', 30));

        if (preg_match('/(.)\1{'.($maximumRepeated - 1).',}/us', $value) === 1) {
            throw new ReviewActionException('reviews.errors.excessive_repetition', ['maximum' => $maximumRepeated]);
        }

        return new self(
            value: $value,
            normalizedHash: hash('sha256', Str::lower($value)),
            linkCount: $linkCount,
        );
    }

    public function authorScopedHash(int $userId): string
    {
        return hash('sha256', 'user:'.$userId.':'.$this->normalizedHash);
    }
}
