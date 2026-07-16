<?php

declare(strict_types=1);

namespace App\DTOs\TechnicalIssues;

use App\Enums\TechnicalIssueDuplicateConfidence;

final readonly class TechnicalIssueDuplicateResult
{
    /** @param list<array{public_id: string, number: string, type: string, status: string}> $candidates */
    public function __construct(public TechnicalIssueDuplicateConfidence $confidence, public array $candidates) {}
}
