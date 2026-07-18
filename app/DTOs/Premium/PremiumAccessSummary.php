<?php

declare(strict_types=1);

namespace App\DTOs\Premium;

use Carbon\CarbonImmutable;

final readonly class PremiumAccessSummary
{
    /**
     * @param  list<string>  $features
     * @param  list<string>  $sources
     */
    public function __construct(
        public bool $active,
        public ?CarbonImmutable $startsAt,
        public ?CarbonImmutable $expiresAt,
        public bool $lifetime,
        public bool $manual,
        public bool $subscription,
        public bool $gracePeriod,
        public bool $cancellationScheduled,
        public bool $regionalRestrictionsApply,
        public array $features,
        public array $sources,
    ) {}

    public static function inactive(): self
    {
        return new self(false, null, null, false, false, false, false, false, true, [], []);
    }
}
