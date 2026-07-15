<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\Enums\CatalogCollectionReportReason;
use App\Enums\CatalogCollectionReportStatus;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionReport;
use App\Models\User;
use App\Support\UserPlainText;
use Illuminate\Support\Facades\DB;
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

        return DB::transaction(function () use ($reporter, $collection, $reason, $details): bool {
            $locked = CatalogCollection::query()->lockForUpdate()->findOrFail($collection->id);
            Gate::forUser($reporter)->authorize('report', $locked);
            $key = hash('sha256', implode('|', [
                $reporter->id,
                $locked->id,
                $locked->content_version,
                $reason->value,
            ]));
            $timestamp = now();

            return CatalogCollectionReport::query()->insertOrIgnore([
                'catalog_collection_id' => $locked->id,
                'collection_public_id' => $locked->public_id,
                'collection_content_version' => $locked->content_version,
                'reporter_id' => $reporter->id,
                'reason' => $reason->value,
                'details' => $details,
                'status' => CatalogCollectionReportStatus::Open->value,
                'deduplication_key' => $key,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]) === 1;
        }, attempts: 3);
    }
}
