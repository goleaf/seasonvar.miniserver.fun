<?php

declare(strict_types=1);

namespace App\Exceptions\Reviews;

use RuntimeException;

final class ReviewActionException extends RuntimeException
{
    /** @param array<string, int|string> $replace */
    public function __construct(
        public readonly string $translationKey,
        public readonly array $replace = [],
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($translationKey);
    }
}
