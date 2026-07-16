<?php

declare(strict_types=1);

namespace App\Exceptions\TechnicalIssues;

use RuntimeException;

final class TechnicalIssueActionException extends RuntimeException
{
    /** @param array<string, int|string> $replace */
    public function __construct(
        public readonly string $translationKey,
        public readonly array $replace = [],
        public readonly ?string $canonicalPublicId = null,
    ) {
        parent::__construct($translationKey);
    }
}
