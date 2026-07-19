<?php

declare(strict_types=1);

namespace App\DTOs\Administration;

use App\Enums\AdminPermission;

final readonly class AdminBulkActionData
{
    public bool $previewRequired;

    public function __construct(
        public string $code,
        public string $label,
        public AdminPermission $permission,
        public int $maximumItems,
        public bool $destructive,
    ) {
        if ($maximumItems < 1 || $maximumItems > 50) {
            throw new \InvalidArgumentException('Administration bulk actions must contain between 1 and 50 items.');
        }

        $this->previewRequired = true;
    }
}
