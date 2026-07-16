<?php

declare(strict_types=1);

namespace App\DTOs\TechnicalIssues;

final readonly class SanitizedIssueText
{
    /** @param list<string> $redactionReasons */
    public function __construct(
        public ?string $value,
        public string $beforeHash,
        public string $afterHash,
        public array $redactionReasons,
    ) {}
}
