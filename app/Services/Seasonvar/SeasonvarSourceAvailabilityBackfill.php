<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\Enums\SeasonvarSourceAvailability;
use App\Models\SourcePage;
use Illuminate\Support\Carbon;

final class SeasonvarSourceAvailabilityBackfill
{
    public function __construct(
        private readonly SeasonvarSourceAvailabilityDetector $detector,
    ) {}

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array{pages_checked: int, pages_updated: int, region_blocked: int, without_known_restriction: int}
     */
    public function run(?callable $progress = null): array
    {
        $result = [
            'pages_checked' => 0,
            'pages_updated' => 0,
            'region_blocked' => 0,
            'without_known_restriction' => 0,
        ];
        $chunkSize = $this->chunkSize();
        $pageLimit = $this->pageLimit();

        $this->report($progress, 'seasonvar-source-availability-backfill-started', [
            'chunk_size' => $chunkSize,
            'page_limit' => $pageLimit,
        ]);

        $pages = SourcePage::query()
            ->select(['id', 'provider_availability_checked_at'])
            ->where('page_type', 'serial')
            ->whereNull('provider_availability_checked_at')
            ->whereHas('latestSnapshot')
            ->with([
                'latestSnapshot' => fn ($query) => $query->select([
                    'source_page_snapshots.id',
                    'source_page_snapshots.source_page_id',
                    'source_page_snapshots.html',
                ]),
            ])
            ->lazyById($chunkSize)
            ->take($pageLimit)
            ->chunk($chunkSize);

        foreach ($pages as $chunk) {
            $blockedIds = [];
            $withoutKnownRestrictionIds = [];

            foreach ($chunk as $page) {
                $status = $this->detector->detect($page->latestSnapshot->html);

                if ($status === SeasonvarSourceAvailability::RegionBlocked) {
                    $blockedIds[] = $page->id;
                } else {
                    $withoutKnownRestrictionIds[] = $page->id;
                }
            }

            $checkedAt = now();

            if ($blockedIds !== []) {
                $result['pages_updated'] += SourcePage::query()->whereKey($blockedIds)->update([
                    'provider_availability_status' => SeasonvarSourceAvailability::RegionBlocked->value,
                    'provider_availability_checked_at' => $checkedAt,
                    'retry_after_at' => $this->retryAfter(),
                ]);
            }

            if ($withoutKnownRestrictionIds !== []) {
                $result['pages_updated'] += SourcePage::query()->whereKey($withoutKnownRestrictionIds)->update([
                    'provider_availability_status' => null,
                    'provider_availability_checked_at' => $checkedAt,
                ]);
            }

            $result['pages_checked'] += count($blockedIds) + count($withoutKnownRestrictionIds);
            $result['region_blocked'] += count($blockedIds);
            $result['without_known_restriction'] += count($withoutKnownRestrictionIds);

            $this->report($progress, 'seasonvar-source-availability-backfill-chunk-complete', $result);
        }

        $this->report($progress, 'seasonvar-source-availability-backfill-complete', $result);

        return $result;
    }

    private function chunkSize(): int
    {
        return max(1, (int) config('seasonvar.provider_availability.backfill_chunk_size', 250));
    }

    private function pageLimit(): int
    {
        return max(1, (int) config('seasonvar.provider_availability.backfill_page_limit', 2000));
    }

    private function retryAfter(): Carbon
    {
        return now()->addHours(max(
            1,
            (int) config('seasonvar.provider_availability.retry_hours', 168),
        ));
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @param  array<string, mixed>  $context
     */
    private function report(?callable $progress, string $event, array $context): void
    {
        if ($progress !== null) {
            $progress($event, $context);
        }
    }
}
