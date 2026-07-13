<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\PlaybackAvailability;

final readonly class CatalogEntitlementDecision
{
    public function __construct(
        public PlaybackAvailability $status,
        public string $message,
    ) {}

    public static function fromStatus(PlaybackAvailability $status): self
    {
        return new self($status, $status->message());
    }

    public function isAllowed(): bool
    {
        return $this->status === PlaybackAvailability::Ready;
    }
}
