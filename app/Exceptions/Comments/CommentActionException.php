<?php

declare(strict_types=1);

namespace App\Exceptions\Comments;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class CommentActionException extends RuntimeException implements ShouldntReport
{
    /** @param array<string, scalar|null> $parameters */
    public function __construct(
        public readonly string $translationKey,
        public readonly array $parameters = [],
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($translationKey);
    }

    public function localizedMessage(): string
    {
        return __($this->translationKey, $this->parameters);
    }
}
