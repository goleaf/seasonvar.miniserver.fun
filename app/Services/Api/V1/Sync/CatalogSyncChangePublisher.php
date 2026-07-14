<?php

declare(strict_types=1);

namespace App\Services\Api\V1\Sync;

use App\Models\ApiSyncChange;
use App\Models\CatalogTitle;
use App\Services\Catalog\CatalogTitleQuery;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class CatalogSyncChangePublisher
{
    public function __construct(private readonly CatalogTitleQuery $titles) {}

    public function publishUpsert(CatalogTitle|int $title, ?string $previousSlug = null): void
    {
        $titleId = $title instanceof CatalogTitle ? (int) $title->getKey() : $title;
        $fallbackSlug = $previousSlug ?? ($title instanceof CatalogTitle ? (string) $title->slug : null);

        if ($titleId < 1) {
            return;
        }

        $this->afterCommit(function () use ($titleId, $fallbackSlug, $previousSlug): void {
            if (! Schema::hasTable('api_sync_changes')) {
                return;
            }

            try {
                $visible = $this->titles->visibleTo(null)
                    ->select(['id', 'slug'])
                    ->whereKey($titleId)
                    ->first();

                if ($visible === null) {
                    $this->record($fallbackSlug, ApiSyncChange::OPERATION_DELETE);

                    return;
                }

                $currentSlug = (string) $visible->slug;

                if ($previousSlug !== null && $previousSlug !== $currentSlug) {
                    $this->record($previousSlug, ApiSyncChange::OPERATION_DELETE);
                }

                $this->record($currentSlug, ApiSyncChange::OPERATION_UPSERT);
            } catch (Throwable $exception) {
                $this->reportFailure($exception);
            }
        });
    }

    public function publishDelete(string $slug): void
    {
        $slug = trim($slug);

        if (! $this->validSlug($slug)) {
            return;
        }

        $this->afterCommit(function () use ($slug): void {
            if (! Schema::hasTable('api_sync_changes')) {
                return;
            }

            try {
                $this->record($slug, ApiSyncChange::OPERATION_DELETE);
            } catch (Throwable $exception) {
                $this->reportFailure($exception);
            }
        });
    }

    private function afterCommit(Closure $callback): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($callback);

            return;
        }

        $callback();
    }

    private function record(?string $slug, string $operation): void
    {
        if ($slug === null || ! $this->validSlug($slug)) {
            return;
        }

        ApiSyncChange::query()->create([
            'scope' => ApiSyncChange::SCOPE_CATALOG,
            'resource_type' => 'title',
            'resource_key' => $slug,
            'operation' => $operation,
            'changed_at' => now(),
        ]);
    }

    private function validSlug(string $slug): bool
    {
        return $slug !== '' && mb_strlen($slug) <= 191;
    }

    private function reportFailure(Throwable $exception): void
    {
        Log::warning('Изменение каталога сохранено, но событие мобильной синхронизации не записано.', [
            'exception' => $exception::class,
        ]);
    }
}
