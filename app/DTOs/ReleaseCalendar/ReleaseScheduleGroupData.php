<?php

declare(strict_types=1);

namespace App\DTOs\ReleaseCalendar;

final readonly class ReleaseScheduleGroupData
{
    /**
     * @param  list<ReleaseScheduleCardData>  $entries
     */
    public function __construct(
        public string $key,
        public ReleaseScheduleCardData $primary,
        public array $entries,
        public bool $isBatch,
        public ?string $batchLabel,
        public ?string $detailLabel,
    ) {}
}
