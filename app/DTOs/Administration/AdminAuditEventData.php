<?php

declare(strict_types=1);

namespace App\DTOs\Administration;

final readonly class AdminAuditEventData
{
    /** @param list<string> $changedFieldLabels */
    public function __construct(
        public string $publicId,
        public string $actionCode,
        public string $actionLabel,
        public string $resourceType,
        public string $resourceLabel,
        public string $resourcePublicId,
        public string $actorName,
        public string $actorPublicId,
        public array $changedFieldLabels,
        public string $occurredAtIso,
        public string $occurredAtLabel,
        public ?string $correlationId,
    ) {}
}
