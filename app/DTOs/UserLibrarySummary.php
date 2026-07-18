<?php

declare(strict_types=1);

namespace App\DTOs;

use Illuminate\Support\Carbon;

final readonly class UserLibrarySummary
{
    /**
     * @param  array{self: string, watchlist: string, ratings: string, continue_watching: string, history: string}  $links
     * @param  array<string, int>  $sectionCounts
     */
    public function __construct(
        public int $watchlistCount,
        public int $ratingsCount,
        public int $continueWatchingCount,
        public int $historyCount,
        public ?Carbon $lastWatchedAt,
        public array $links,
        public array $sectionCounts = [],
    ) {}
}
