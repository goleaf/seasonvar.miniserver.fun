<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class BrowserSessionSummaryData
{
    public function __construct(
        public string $wireKey,
        public ?string $opaqueToken,
        public string $deviceLabel,
        public string $lastActivityLabel,
        public string $lastActivityIso,
        public bool $current,
    ) {}
}
