<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ApiSyncCursor
{
    public function __construct(
        public string $scope,
        public ?int $ownerId,
        public int $changeId,
        public int $issuedAt = 0,
    ) {}
}
