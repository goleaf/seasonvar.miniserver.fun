<?php

declare(strict_types=1);

namespace App\DTOs\TechnicalIssues;

use App\Models\TechnicalIssue;

final readonly class TechnicalIssueCreationResult
{
    public function __construct(public TechnicalIssue $issue, public bool $existingExactDuplicate) {}
}
