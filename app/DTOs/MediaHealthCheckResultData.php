<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\MediaHealthErrorCategory;
use Illuminate\Support\Carbon;

final readonly class MediaHealthCheckResultData
{
    public function __construct(
        public bool $available,
        public string $checkStatus,
        public ?int $httpStatus,
        public Carbon $checkedAt,
        public ?int $latencyMs,
        public ?MediaHealthErrorCategory $errorCategory = null,
        public bool $permanentFailure = false,
    ) {}
}
