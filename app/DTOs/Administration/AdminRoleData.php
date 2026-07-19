<?php

declare(strict_types=1);

namespace App\DTOs\Administration;

final readonly class AdminRoleData
{
    /** @param list<AdminPermissionData> $permissions */
    public function __construct(
        public string $code,
        public string $label,
        public bool $active,
        public array $permissions,
    ) {}
}
