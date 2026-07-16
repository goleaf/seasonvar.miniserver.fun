<?php

declare(strict_types=1);

namespace App\DTOs\ContentRequests;

final readonly class ContentExistenceResult
{
    /** @param list<array{kind: string, label: string, url: string}> $matches */
    public function __construct(public bool $exact, public array $matches = []) {}
}
