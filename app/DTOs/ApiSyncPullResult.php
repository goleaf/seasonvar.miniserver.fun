<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\ApiSyncChange;
use Illuminate\Database\Eloquent\Collection;

final readonly class ApiSyncPullResult
{
    /** @param Collection<int, ApiSyncChange> $changes */
    public function __construct(
        public Collection $changes,
        public string $cursor,
        public bool $hasMore,
    ) {}
}
