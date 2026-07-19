<?php

declare(strict_types=1);

namespace App\DTOs\Administration;

final readonly class AdminMembershipData
{
    public function __construct(
        public string $publicId,
        public string $userPublicId,
        public string $userName,
        public string $maskedEmail,
        public string $roleCode,
        public string $roleLabel,
        public string $status,
        public string $statusLabel,
        public string $assignedAtLabel,
        public string $expiresAtLabel,
    ) {}
}
