<?php

declare(strict_types=1);

namespace App\Services\Collections\Import;

use App\DTOs\CatalogCollectionSyncResult;
use App\DTOs\HdRezkaCollectionDefinition;
use App\Enums\CatalogCollectionSourceMatchStatus;
use App\Enums\CatalogCollectionSyncStatus;
use App\Jobs\RebuildCatalogRecommendationsAfterCollectionSync;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionSource;
use App\Models\CatalogCollectionSyncRun;
use App\Services\Catalog\CatalogRecommendationDirtyTitleTracker;
use App\Services\Crawler\PoliteHttpClient;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use LogicException;
use RuntimeException;
use Throwable;

final readonly class HdRezkaCollectionSyncService
{
    private const int MAX_ERRORS = 50;

    public function __construct(
        private HdRezkaCollectionUrlGuard $urlGuard,
        private HdRezkaCollectionParser $parser,
        private HdRezkaCollectionMatcher $matcher,
        private HdRezkaCollectionCoverImporter $covers,
        private HdRezkaCollectionReconciler $reconciler,
        private HdRezkaCollectionSignalSynchronizer $signals,
        private PoliteHttpClient $http,
        private CatalogRecommendationDirtyTitleTracker $dirtyTitles,
    ) {}

    /** @param (callable(string, array<string, mixed>): void)|null $progress */
    public function sync(
        bool $dryRun = false,
        bool $retryUnresolved = false,
        ?callable $progress = null,
    ): CatalogCollectionSyncResult {
        if (! (bool) config('catalog-collection-imports.hdrezka.enabled', false)) {
            throw new LogicException('Синхронизация HDRezka выключена в конфигурации.');
        }

        $provider = (string) config('catalog-collection-imports.hdrezka.provider', 'hdrezka');
        $lock = Cache::store((string) config('catalog-collection-imports.hdrezka.lock_store', 'redis-locks'))
            ->lock(
                'catalog-collections:sync:'.$provider,
                $this->boundedConfig('catalog-collection-imports.hdrezka.lock_seconds', 21_600, 60, 86_400),
            );

        if (! $lock->get()) {
            return new CatalogCollectionSyncResult(
                status: CatalogCollectionSyncStatus::Failed,
                counters: $this->emptyCounters(),
                errors: ['Синхронизация коллекций уже выполняется.'],
                runId: null,
                dryRun: $dryRun,
            );
        }

        try {
            return $this->performSync($provider, $dryRun, $retryUnresolved, $progress);
        } finally {
            $this->release($lock);
        }
    }

    /** @param (callable(string, array<string, mixed>): void)|null $progress */
    private function performSync(
        string $provider,
        bool $dryRun,
        bool $retryUnresolved,
        ?callable $progress,
    ): CatalogCollectionSyncResult {
        $counters = $this->emptyCounters();
        $errors = [];
        $run = $dryRun ? null : CatalogCollectionSyncRun::query()->create([
            'provider' => $provider,
            'status' => CatalogCollectionSyncStatus::Running,
            'counters' => $counters,
            'started_at' => now(),
        ]);

        try {
            $indexHtml = $this->fetchHtml(
                (string) config('catalog-collection-imports.hdrezka.index_path', '/collections.html'),
                HdRezkaCollectionUrlGuard::PURPOSE_INDEX,
            );
            $definitions = $this->parser->collections($indexHtml);
        } catch (Throwable) {
            $this->addError($errors, 'Страница списка коллекций недоступна или имеет некорректный формат.');

            return $this->finish(
                $run,
                CatalogCollectionSyncStatus::Failed,
                $counters,
                $errors,
                $dryRun,
            );
        }

        $counters['collections_discovered'] = count($definitions);
        $collectionLimit = $this->boundedConfig(
            'catalog-collection-imports.hdrezka.max_collections',
            200,
            1,
            1000,
        );
        $limited = count($definitions) > $collectionLimit;

        if ($limited) {
            $this->addError($errors, 'Достигнут настроенный лимит количества коллекций.');
            $definitions = array_slice($definitions, 0, $collectionLimit);
        }

        $materialChanged = false;
        $matchedTitleIds = [];

        foreach ($definitions as $index => $definition) {
            try {
                $processed = $this->processCollection(
                    $definition,
                    $index + 1,
                    $run,
                    $dryRun,
                    $retryUnresolved,
                    $progress,
                );
            } catch (Throwable) {
                $counters['collection_failures']++;
                $this->addError($errors, 'Подборка №'.($index + 1).' не обработана из-за безопасно остановленной ошибки.');

                continue;
            }

            foreach ($processed['counters'] as $key => $value) {
                $counters[$key] = ($counters[$key] ?? 0) + $value;
            }

            foreach ($processed['errors'] as $error) {
                $this->addError($errors, $error);
            }

            $materialChanged = $materialChanged || $processed['material_changed'];

            foreach ($processed['matched_title_ids'] as $catalogTitleId) {
                $matchedTitleIds[$catalogTitleId] = true;
            }
        }

        $status = $limited || $counters['collection_failures'] > 0
            ? CatalogCollectionSyncStatus::Partial
            : CatalogCollectionSyncStatus::Completed;

        if ($run instanceof CatalogCollectionSyncRun && $status === CatalogCollectionSyncStatus::Completed) {
            try {
                $missing = $this->reconciler->reconcileMissingSources($run);
                $counters['sources_missing'] += $missing['sources_missing'];
                $counters['removed'] += $missing['removed'];
                $materialChanged = $materialChanged || $missing['sources_missing'] > 0;

                foreach ($missing['title_ids'] as $catalogTitleId) {
                    $matchedTitleIds[$catalogTitleId] = true;
                }
            } catch (Throwable) {
                $status = CatalogCollectionSyncStatus::Partial;
                $this->addError($errors, 'Исчезнувшие подборки источника не удалось безопасно сверить.');
            }
        }

        if ($run instanceof CatalogCollectionSyncRun) {
            $run->forceFill([
                'status' => $status,
                'completed_at' => now(),
                'counters' => $counters,
                'error_summary' => $this->errorSummary($errors),
            ])->save();

            try {
                $signalResult = $this->signals->synchronizeForRun($run->refresh());
                $counters['signals_upserted'] = $signalResult['upserted'];
                $counters['signals_deleted'] = $signalResult['deleted'];

                foreach ($signalResult['title_ids'] as $catalogTitleId) {
                    $matchedTitleIds[$catalogTitleId] = true;
                }
            } catch (Throwable) {
                $status = CatalogCollectionSyncStatus::Partial;
                $this->addError($errors, 'Recommendation signals обновлены не полностью.');
            }

            $titleIds = array_map('intval', array_keys($matchedTitleIds));

            if ($materialChanged && $titleIds !== []) {
                $this->dirtyTitles->markMany($titleIds, 'editorial-collection-sync');

                if ((bool) config(
                    'catalog-collection-imports.hdrezka.recommendation_rebuild.enabled',
                    true,
                )) {
                    try {
                        Bus::dispatch(new RebuildCatalogRecommendationsAfterCollectionSync);
                    } catch (Throwable) {
                        $status = CatalogCollectionSyncStatus::Partial;
                        $this->addError($errors, 'Перестроение рекомендаций не удалось поставить в очередь.');
                    }
                }
            }

            $run->forceFill([
                'status' => $status,
                'counters' => $counters,
                'error_summary' => $this->errorSummary($errors),
                'completed_at' => now(),
            ])->save();
        }

        $this->report($progress, 'catalog-collections-sync-complete', [
            'status' => $status->value,
            ...$counters,
        ]);

        return new CatalogCollectionSyncResult(
            status: $status,
            counters: $counters,
            errors: $errors,
            runId: $run?->id,
            dryRun: $dryRun,
        );
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array{counters: array<string, int>, errors: list<string>, material_changed: bool, matched_title_ids: list<int>}
     */
    private function processCollection(
        HdRezkaCollectionDefinition $definition,
        int $collectionPosition,
        ?CatalogCollectionSyncRun $run,
        bool $dryRun,
        bool $retryUnresolved,
        ?callable $progress,
    ): array {
        $page = 1;
        $path = $definition->path;
        $seenPaths = [];
        $seenContent = [];
        $resolved = [];
        $errors = [];
        $pages = 0;
        $complete = true;
        $maxPages = $this->boundedConfig(
            'catalog-collection-imports.hdrezka.max_pages_per_collection',
            100,
            1,
            500,
        );
        $maxItems = $this->boundedConfig(
            'catalog-collection-imports.hdrezka.max_items_per_collection',
            10_000,
            1,
            100_000,
        );
        $detailFailures = 0;

        while ($path !== null) {
            if ($page > $maxPages) {
                $complete = false;
                $errors[] = "Подборка №{$collectionPosition}: достигнут лимит страниц.";

                break;
            }

            $pathKey = hash('sha256', mb_strtolower(rawurldecode($path)));

            if (isset($seenPaths[$pathKey])) {
                $complete = false;
                $errors[] = "Подборка №{$collectionPosition}: остановлена повторяющаяся pagination.";

                break;
            }

            $seenPaths[$pathKey] = true;

            try {
                $html = $this->fetchHtml($path, HdRezkaCollectionUrlGuard::PURPOSE_COLLECTION);
            } catch (Throwable) {
                $complete = false;
                $errors[] = "Подборка №{$collectionPosition}: страница источника недоступна.";

                break;
            }

            $contentHash = hash('sha256', $html);

            if (isset($seenContent[$contentHash])) {
                $complete = false;
                $errors[] = "Подборка №{$collectionPosition}: остановлена повторяющаяся страница.";

                break;
            }

            $seenContent[$contentHash] = true;

            try {
                $parsed = $this->parser->page($html, $definition->path, $page);
            } catch (Throwable) {
                $complete = false;
                $errors[] = "Подборка №{$collectionPosition}: HTML страницы не распознан.";

                break;
            }

            $pages++;
            $itemLimitReached = false;

            foreach ($parsed['items'] as $item) {
                if (count($resolved) >= $maxItems) {
                    $complete = false;
                    $itemLimitReached = true;
                    $errors[] = "Подборка №{$collectionPosition}: достигнут лимит тайтлов.";

                    break;
                }

                $match = $this->matcher->match($item);

                if ($match->status === CatalogCollectionSourceMatchStatus::Ambiguous
                    || ($retryUnresolved && $match->status === CatalogCollectionSourceMatchStatus::Unmatched)) {
                    try {
                        $detail = $this->parser->detail($this->fetchHtml(
                            $item->detailPath,
                            HdRezkaCollectionUrlGuard::PURPOSE_DETAIL,
                        ));
                        $match = $this->matcher->match($item, $detail);
                    } catch (Throwable) {
                        $detailFailures++;
                    }
                }

                $resolved[] = ['item' => $item, 'match' => $match];
            }

            if ($itemLimitReached) {
                break;
            }

            $path = $parsed['next_path'];
            $page++;
        }

        $counts = collect($resolved)->countBy(fn (array $value): string => $value['match']->status->value);
        $counters = [
            'collections_processed' => 1,
            'collection_failures' => $complete ? 0 : 1,
            'pages' => $pages,
            'items' => count($resolved),
            'matched' => (int) $counts->get(CatalogCollectionSourceMatchStatus::Matched->value, 0),
            'ambiguous' => (int) $counts->get(CatalogCollectionSourceMatchStatus::Ambiguous->value, 0),
            'unmatched' => (int) $counts->get(CatalogCollectionSourceMatchStatus::Unmatched->value, 0),
            'created' => 0,
            'membership_changed' => 0,
            'removed' => 0,
            'covers_updated' => 0,
            'covers_failed' => 0,
            'detail_failures' => $detailFailures,
            'sources_reactivated' => 0,
            'sources_missing' => 0,
        ];
        $materialChanged = false;

        if (! $dryRun && $run instanceof CatalogCollectionSyncRun) {
            $reconciliation = $this->reconciler->reconcile($run, $definition, $resolved, $complete);
            $counters['created'] = $reconciliation['created'] ? 1 : 0;
            $counters['membership_changed'] = $reconciliation['membership_changed'] ? 1 : 0;
            $counters['removed'] = $reconciliation['removed'];
            $counters['sources_reactivated'] = $reconciliation['source_reactivated'] ? 1 : 0;
            $materialChanged = $reconciliation['created']
                || $reconciliation['membership_changed']
                || $reconciliation['source_reactivated'];

            if ($definition->coverPath !== null) {
                try {
                    $preparedCover = $this->covers->prepare($definition->coverPath);

                    if ($preparedCover === null) {
                        throw new RuntimeException('Обложка источника не прошла проверку.');
                    }

                    $collection = CatalogCollection::query()->findOrFail($reconciliation['collection_id']);
                    $coverChanged = $this->covers->apply($collection, $preparedCover);
                    $counters['covers_updated'] += $coverChanged ? 1 : 0;
                    $materialChanged = $materialChanged || $coverChanged;
                    CatalogCollectionSource::query()
                        ->where('provider', $run->provider)
                        ->where('source_key', $definition->sourceKey)
                        ->update([
                            'cover_path' => $collection->fresh()?->cover_path,
                            'cover_content_hash' => $preparedCover->contentHash,
                            'updated_at' => now(),
                        ]);
                } catch (Throwable) {
                    $counters['covers_failed']++;
                    $errors[] = "Подборка №{$collectionPosition}: обложка не обновлена.";
                }
            }
        }

        $matchedTitleIds = collect($resolved)
            ->filter(fn (array $value): bool => $value['match']->status === CatalogCollectionSourceMatchStatus::Matched)
            ->pluck('match.catalogTitleId')
            ->filter(fn (mixed $id): bool => is_int($id) && $id > 0)
            ->unique()
            ->values()
            ->all();

        $this->report($progress, 'catalog-collection-processed', [
            'position' => $collectionPosition,
            'complete' => $complete,
            ...$counters,
        ]);

        return [
            'counters' => $counters,
            'errors' => $errors,
            'material_changed' => $materialChanged,
            'matched_title_ids' => $matchedTitleIds,
        ];
    }

    private function fetchHtml(string $urlOrPath, string $purpose): string
    {
        $url = $this->urlGuard->absolute($urlOrPath, $purpose);
        $response = $this->fetchResponse($url);

        if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
            $redirectUrl = $this->urlGuard->absolute($response->header('Location'), $purpose);

            if ($redirectUrl === $url) {
                throw new RuntimeException('Внешний источник вернул циклический redirect.');
            }

            $response = $this->fetchResponse($redirectUrl);

            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                throw new RuntimeException('Внешний источник превысил лимит redirect.');
            }
        }

        if (! $response->successful()) {
            throw new RuntimeException('Внешний источник вернул ошибку HTTP.');
        }

        $contentType = mb_strtolower(trim(explode(';', $response->header('Content-Type'))[0] ?? ''));

        if (! in_array($contentType, ['text/html', 'application/xhtml+xml'], true)) {
            throw new RuntimeException('Внешний источник вернул неожиданный тип документа.');
        }

        $body = $response->body();

        if ($body === '' || ! mb_check_encoding($body, 'UTF-8')) {
            throw new RuntimeException('Внешний источник вернул пустой документ.');
        }

        return $body;
    }

    private function fetchResponse(string $url): Response
    {
        return $this->http->get(
            $url,
            delaySeconds: $this->boundedConfig(
                'catalog-collection-imports.hdrezka.delay_seconds',
                3,
                0,
                60,
            ),
            headers: ['Accept' => 'text/html,application/xhtml+xml;q=0.9'],
            maxResponseBytes: $this->boundedConfig(
                'catalog-collection-imports.hdrezka.max_response_bytes',
                4_194_304,
                1,
                16_777_216,
            ),
            httpVersion: (string) config('catalog-collection-imports.hdrezka.http_version', '2.0'),
        );
    }

    /** @param array<string, int> $counters */
    private function finish(
        ?CatalogCollectionSyncRun $run,
        CatalogCollectionSyncStatus $status,
        array $counters,
        array $errors,
        bool $dryRun,
    ): CatalogCollectionSyncResult {
        if ($run instanceof CatalogCollectionSyncRun) {
            $run->forceFill([
                'status' => $status,
                'counters' => $counters,
                'error_summary' => $this->errorSummary($errors),
                'completed_at' => now(),
            ])->save();
        }

        return new CatalogCollectionSyncResult(
            status: $status,
            counters: $counters,
            errors: $errors,
            runId: $run?->id,
            dryRun: $dryRun,
        );
    }

    /** @return array<string, int> */
    private function emptyCounters(): array
    {
        return [
            'collections_discovered' => 0,
            'collections_processed' => 0,
            'collection_failures' => 0,
            'pages' => 0,
            'items' => 0,
            'matched' => 0,
            'ambiguous' => 0,
            'unmatched' => 0,
            'created' => 0,
            'membership_changed' => 0,
            'removed' => 0,
            'covers_updated' => 0,
            'covers_failed' => 0,
            'detail_failures' => 0,
            'sources_reactivated' => 0,
            'sources_missing' => 0,
            'signals_upserted' => 0,
            'signals_deleted' => 0,
        ];
    }

    /** @param list<string> $errors */
    private function addError(array &$errors, string $error): void
    {
        if (count($errors) < self::MAX_ERRORS) {
            $errors[] = $error;
        }
    }

    /** @param list<string> $errors */
    private function errorSummary(array $errors): ?string
    {
        if ($errors === []) {
            return null;
        }

        return mb_substr(implode(' ', $errors), 0, 1000);
    }

    private function boundedConfig(string $key, int $default, int $minimum, int $maximum): int
    {
        return max($minimum, min($maximum, (int) config($key, $default)));
    }

    /** @param (callable(string, array<string, mixed>): void)|null $progress */
    private function report(?callable $progress, string $event, array $context): void
    {
        if ($progress !== null) {
            $progress($event, $context);
        }
    }

    private function release(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
