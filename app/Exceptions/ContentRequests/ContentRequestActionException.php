<?php

declare(strict_types=1);

namespace App\Exceptions\ContentRequests;

use RuntimeException;

final class ContentRequestActionException extends RuntimeException
{
    /** @param array<string, int|string> $replace */
    public function __construct(
        public readonly string $translationKey,
        public readonly array $replace = [],
        public readonly ?string $canonicalPublicId = null,
        public readonly ?string $canonicalUrl = null,
        public readonly int $retryAfter = 0,
    ) {
        parent::__construct($translationKey);
    }
}
