<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\Enums\CatalogCollectionReportReason;
use App\Enums\CatalogCollectionReportStatus;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionReport;
use App\Models\User;
use App\Support\UserPlainText;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CatalogCollectionReportService
{
    public function __construct(private readonly CatalogCollectionRateLimiter $rateLimiter) {}

    public function submit(
        User $reporter,
        CatalogCollection $collection,
        CatalogCollectionReportReason $reason,
        ?string $details,
    ): bool {
        Gate::forUser($reporter)->authorize('report', $collection);
        $this->rateLimiter->ensure($reporter, 'report', 'reportDetails');

        $details = UserPlainText::description($details);

        if ($details !== null && mb_strlen($details) > (int) config('catalog-collections.report_details_max_length', 2_000)) {
            throw ValidationException::withMessages(['reportDetails' => [__('collections.validation.report_details')]]);
        }

        $key = hash('sha256', implode('|', [
            $reporter->id,
            $collection->id,
            $collection->content_version,
            $reason->value,
        ]));
        $report = CatalogCollectionReport::query()->firstOrCreate([
            'deduplication_key' => $key,
        ], [
            'catalog_collection_id' => $collection->id,
            'collection_public_id' => $collection->public_id,
            'collection_content_version' => $collection->content_version,
            'reporter_id' => $reporter->id,
            'reason' => $reason,
            'details' => $details,
            'status' => CatalogCollectionReportStatus::Open,
        ]);

        return $report->wasRecentlyCreated;
    }
}
