<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\DTOs\Seasonvar\SeasonvarImportDispatchBatch;
use App\Enums\SeasonvarImportStatus;
use App\Enums\SeasonvarImportTitleGroupStatus;
use App\Enums\SeasonvarPageType;
use App\Enums\SeasonvarPreparedPageStatus;
use App\Jobs\ImportSeasonvarSourcePage;
use App\Jobs\PrepareSeasonvarImportTitlePage;
use App\Jobs\ReconcileSeasonvarQueuedImportRun;
use App\Models\CatalogTitle;
use App\Models\Season;
use App\Models\SeasonvarImportPreparedPage;
use App\Models\SeasonvarImportRun;
use App\Models\SeasonvarImportTitleGroup;
use App\Models\SourcePage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SeasonvarImportDispatchBatcher
{
    public function __construct(
        private readonly SeasonvarRefreshPlanner $refreshPlanner,
        private readonly SeasonvarPageClaimManager $claims,
        private readonly SeasonvarImportGroupKey $groupKeys,
        private readonly SeasonvarDatabaseTransaction $transactions,
        private readonly SeasonvarImportFinalizationDispatcher $finalizers,
    ) {}

    public function dispatchNext(int $runId): SeasonvarImportDispatchBatch
    {
        $run = SeasonvarImportRun::query()->find($runId);

        if (! $run instanceof SeasonvarImportRun || ! $this->canDispatch($run)) {
            return new SeasonvarImportDispatchBatch(
                registeredPages: 0,
                jobsDispatched: 0,
                hasMore: false,
                dispatchCompleted: data_get($run?->summary, 'dispatch_completed') === true,
            );
        }

        $batchSize = max(
            1,
            min(100, (int) config('seasonvar.import.chunk_size', 100)),
        );
        [$candidates, $hasMore] = $this->candidates($run, $batchSize);
        $registration = $this->transactions->run(
            fn (): array => $this->registerPages(
                $runId,
                $candidates,
                ! $hasMore,
            ),
            attempts: 3,
            baseDelayMilliseconds: 100,
        );
        $jobsDispatched = $this->dispatchPreparedPages(
            $runId,
            $registration['prepared_page_ids'],
            $registration['queue_name'],
        );
        $jobsDispatched += $this->dispatchNonSerialPages(
            $runId,
            $registration['non_serial_jobs'],
            $registration['queue_name'],
        );

        foreach ($registration['new_group_ids'] as $groupId) {
            $group = SeasonvarImportTitleGroup::query()->find($groupId);

            if ($group instanceof SeasonvarImportTitleGroup) {
                $this->finalizers->signalTitleGroup($group);
            }
        }

        if ($hasMore && $registration['active']) {
            ReconcileSeasonvarQueuedImportRun::dispatch($runId)->afterCommit();
        }

        return new SeasonvarImportDispatchBatch(
            registeredPages: count($registration['prepared_page_ids'])
                + count($registration['non_serial_jobs']),
            jobsDispatched: $jobsDispatched,
            hasMore: $hasMore && $registration['active'],
            dispatchCompleted: $registration['dispatch_completed'],
        );
    }

    /**
     * @return array{0: Collection<int, SourcePage>, 1: bool}
     */
    private function candidates(
        SeasonvarImportRun $run,
        int $batchSize,
    ): array {
        $summary = $run->summary ?? [];
        $pageTypes = is_array($summary['page_types'] ?? null)
            ? array_values($summary['page_types'])
            : null;
        $tailPageIds = is_array($summary['sitemap_tail_page_ids'] ?? null)
            ? array_values($summary['sitemap_tail_page_ids'])
            : null;
        $refreshAfter = now()->subHours(max(
            1,
            (int) config('seasonvar.import.refresh_after_hours', 24),
        ));
        $chunks = match (true) {
            $tailPageIds !== null => $this->refreshPlanner->forcedPageChunksForIds(
                $tailPageIds,
                $batchSize,
                $run->id,
            ),
            (bool) $run->force => $this->refreshPlanner->forcedPageChunks(
                $batchSize,
                $run->id,
                pageTypes: $pageTypes,
            ),
            default => $this->refreshPlanner->pageChunksForImportCycle(
                $batchSize,
                $refreshAfter,
                $run->id,
                pageTypes: $pageTypes,
            ),
        };
        $selected = collect();
        $hasMore = false;

        foreach ($chunks as $pages) {
            foreach ($pages as $page) {
                if ($selected->count() >= $batchSize) {
                    $hasMore = true;

                    break 2;
                }

                $selected->push($page);
            }
        }

        return [$selected->values(), $hasMore];
    }

    /**
     * @param  Collection<int, SourcePage>  $pages
     * @return array{
     *     active: bool,
     *     dispatch_completed: bool,
     *     prepared_page_ids: list<int>,
     *     non_serial_jobs: list<array{
     *         source_page_id: int,
     *         claim_token: string,
     *         group_key: string,
     *         force: bool
     *     }>,
     *     new_group_ids: list<int>,
     *     queue_name: string
     * }
     */
    private function registerPages(
        int $runId,
        Collection $pages,
        bool $plannerExhausted,
    ): array {
        $run = SeasonvarImportRun::query()->lockForUpdate()->find($runId);
        $queueName = (string) config(
            'seasonvar.queue.queue',
            'seasonvar-import',
        );

        if (! $run instanceof SeasonvarImportRun || ! $this->canDispatch($run)) {
            return [
                'active' => false,
                'dispatch_completed' => data_get(
                    $run?->summary,
                    'dispatch_completed',
                ) === true,
                'prepared_page_ids' => [],
                'non_serial_jobs' => [],
                'new_group_ids' => [],
                'queue_name' => $queueName,
            ];
        }

        $pageIds = $pages
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $registeredPageIds = SeasonvarImportPreparedPage::query()
            ->where('seasonvar_import_run_id', $runId)
            ->whereIn('source_page_id', $pageIds)
            ->pluck('source_page_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $unregisteredPages = $pages
            ->whereNotIn('id', $registeredPageIds)
            ->values();
        $claim = $this->claims->claimMany(
            $unregisteredPages
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all(),
            $runId,
        );
        $ownedPageIds = array_fill_keys($claim['page_ids'], true);
        $ownedPages = $unregisteredPages
            ->filter(
                static fn (SourcePage $page): bool => isset(
                    $ownedPageIds[(int) $page->id],
                ),
            )
            ->sortBy('id')
            ->values();
        $ownedSerialPages = $ownedPages
            ->where('page_type', SeasonvarPageType::Serial->value)
            ->values();
        $ownedNonSerialPages = $ownedPages
            ->where('page_type', '!=', SeasonvarPageType::Serial->value)
            ->values();
        $groupData = $this->groupDefinitions(
            $ownedSerialPages,
            $this->resolveCatalogTitleIds($ownedSerialPages),
        );
        $groupDefinitions = $groupData['groups'];
        $pageHashes = $groupData['page_hashes'];
        $existingGroupIds = $this->existingGroupIds(
            $runId,
            array_keys($groupDefinitions),
        );
        $now = now();

        if ($groupDefinitions !== []) {
            SeasonvarImportTitleGroup::query()->insertOrIgnore(
                collect($groupDefinitions)
                    ->map(
                        static fn (array $definition, string $hash): array => [
                            'seasonvar_import_run_id' => $runId,
                            'catalog_title_id' => $definition['catalog_title_id'],
                            'group_key_hash' => $hash,
                            'queue_name' => $queueName,
                            'status' => SeasonvarImportTitleGroupStatus::Running->value,
                            'expected_pages' => 0,
                            'prepared_pages' => 0,
                            'failed_pages' => 0,
                            'applied_pages' => 0,
                            'started_at' => $now,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                    )
                    ->values()
                    ->all(),
            );
        }

        $groups = SeasonvarImportTitleGroup::query()
            ->select(['id', 'group_key_hash', 'catalog_title_id'])
            ->where('seasonvar_import_run_id', $runId)
            ->whereIn('group_key_hash', array_keys($groupDefinitions))
            ->get()
            ->keyBy('group_key_hash');
        $this->fillMissingCatalogTitles($groups, $groupDefinitions);
        $beforePreparedIds = SeasonvarImportPreparedPage::query()
            ->where('seasonvar_import_run_id', $runId)
            ->whereIn(
                'source_page_id',
                $ownedSerialPages->pluck('id')->all(),
            )
            ->pluck('source_page_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($ownedSerialPages->isNotEmpty()) {
            SeasonvarImportPreparedPage::query()->insertOrIgnore(
                $ownedSerialPages
                    ->map(function (SourcePage $page) use (
                        $groups,
                        $pageHashes,
                        $runId,
                        $now,
                    ): array {
                        $hash = $pageHashes[(int) $page->id]
                            ?? $this->groupHash($page);
                        $group = $groups->get($hash);

                        return [
                            'seasonvar_import_run_id' => $runId,
                            'seasonvar_import_title_group_id' => $group->id,
                            'source_page_id' => $page->id,
                            'status' => SeasonvarPreparedPageStatus::Queued->value,
                            'warnings' => json_encode([], JSON_THROW_ON_ERROR),
                            'last_enqueue_attempt_at' => null,
                            'enqueue_attempts' => 0,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    })
                    ->all(),
            );
        }

        $prepared = SeasonvarImportPreparedPage::query()
            ->select([
                'id',
                'source_page_id',
                'seasonvar_import_title_group_id',
            ])
            ->where('seasonvar_import_run_id', $runId)
            ->whereIn(
                'source_page_id',
                $ownedSerialPages->pluck('id')->all(),
            )
            ->orderBy('id')
            ->get();
        $beforeLookup = array_fill_keys($beforePreparedIds, true);
        $newPrepared = $prepared
            ->reject(
                static fn (SeasonvarImportPreparedPage $page): bool => isset(
                    $beforeLookup[(int) $page->source_page_id],
                ),
            )
            ->values();
        $this->incrementExpectedPages(
            $newPrepared
                ->countBy('seasonvar_import_title_group_id')
                ->mapWithKeys(
                    static fn (int $count, int|string $groupId): array => [
                        (int) $groupId => $count,
                    ],
                )
                ->all(),
        );

        $summary = $run->summary ?? [];
        $summary['dispatch_batches'] = max(
            0,
            (int) ($summary['dispatch_batches'] ?? 0),
        ) + 1;
        $dispatchCompleted = $plannerExhausted;
        $summary['dispatch_completed'] = $dispatchCompleted;
        $run->summary = $summary;
        $run->selected = (int) $run->selected
            + $newPrepared->count()
            + $ownedNonSerialPages->count();
        $run->last_progress_at = $now;
        $run->last_heartbeat_at = $now;
        $run->save();

        return [
            'active' => true,
            'dispatch_completed' => $dispatchCompleted,
            'prepared_page_ids' => $newPrepared
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all(),
            'non_serial_jobs' => $ownedNonSerialPages
                ->map(fn (SourcePage $page): array => [
                    'source_page_id' => (int) $page->id,
                    'claim_token' => $claim['token'],
                    'group_key' => $this->groupKeys->forUrl(
                        $page->url,
                        $page->url_hash,
                    ),
                    'force' => (bool) $run->force,
                ])
                ->all(),
            'new_group_ids' => $groups
                ->reject(
                    static fn (SeasonvarImportTitleGroup $group): bool => isset(
                        $existingGroupIds[(int) $group->id],
                    ),
                )
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all(),
            'queue_name' => $queueName,
        ];
    }

    private function canDispatch(SeasonvarImportRun $run): bool
    {
        $summary = $run->summary ?? [];
        $discoveryCompleted = array_key_exists(
            'discovery_completed',
            $summary,
        )
            ? $summary['discovery_completed'] === true
            : ! (bool) ($summary['discover'] ?? true);

        return $run->mode === 'sitemap'
            && $run->execution_mode === 'queue'
            && $run->status === SeasonvarImportStatus::Running->value
            && $discoveryCompleted
            && data_get($summary, 'dispatch_completed') === false;
    }

    /**
     * @param  Collection<int, SourcePage>  $pages
     * @return array<int, int>
     */
    private function resolveCatalogTitleIds(Collection $pages): array
    {
        if ($pages->isEmpty()) {
            return [];
        }

        $pageIds = $pages->pluck('id')->all();
        $urlHashes = $pages->pluck('url_hash')->unique()->all();
        $directByPage = [];
        $directByHash = [];

        CatalogTitle::query()
            ->select(['id', 'source_id', 'source_page_id', 'source_url_hash'])
            ->where(function ($query) use ($pageIds, $urlHashes): void {
                $query->whereIn('source_page_id', $pageIds)
                    ->orWhereIn('source_url_hash', $urlHashes);
            })
            ->orderBy('id')
            ->get()
            ->each(function (CatalogTitle $title) use (
                &$directByHash,
                &$directByPage,
            ): void {
                $sourceId = (int) $title->source_id;

                if ($title->source_page_id !== null) {
                    $key = $sourceId.':'.(int) $title->source_page_id;
                    $directByPage[$key] ??= (int) $title->id;
                }

                if ($title->source_url_hash !== '') {
                    $key = $sourceId.':'.$title->source_url_hash;
                    $directByHash[$key] ??= (int) $title->id;
                }
            });

        $seasonTable = (new Season)->getTable();
        $titleTable = (new CatalogTitle)->getTable();
        $seasonByHash = [];

        Season::query()
            ->select([
                $seasonTable.'.source_url_hash',
                $seasonTable.'.catalog_title_id',
                $titleTable.'.source_id as catalog_source_id',
            ])
            ->join(
                $titleTable,
                $titleTable.'.id',
                '=',
                $seasonTable.'.catalog_title_id',
            )
            ->whereIn($seasonTable.'.source_url_hash', $urlHashes)
            ->whereNull($titleTable.'.deleted_at')
            ->orderBy($seasonTable.'.catalog_title_id')
            ->get()
            ->each(function (Season $season) use (&$seasonByHash): void {
                $key = (int) $season->getAttribute('catalog_source_id')
                    .':'.(string) $season->source_url_hash;
                $seasonByHash[$key] ??= (int) $season->catalog_title_id;
            });

        return $pages->mapWithKeys(function (SourcePage $page) use (
            $directByHash,
            $directByPage,
            $seasonByHash,
        ): array {
            $sourceId = (int) $page->source_id;
            $ids = array_filter([
                $directByPage[$sourceId.':'.(int) $page->id] ?? null,
                $directByHash[$sourceId.':'.$page->url_hash] ?? null,
                $seasonByHash[$sourceId.':'.$page->url_hash] ?? null,
            ], static fn (?int $id): bool => $id !== null);

            return [(int) $page->id => $ids === [] ? null : min($ids)];
        })->filter()->all();
    }

    /**
     * @param  Collection<int, SourcePage>  $pages
     * @param  array<int, int>  $catalogTitleIds
     * @return array{
     *     groups: array<string, array{catalog_title_id: int|null}>,
     *     page_hashes: array<int, string>
     * }
     */
    private function groupDefinitions(
        Collection $pages,
        array $catalogTitleIds,
    ): array {
        $definitions = [];
        $pageHashes = [];

        foreach ($pages as $page) {
            $hash = $this->groupHash($page);
            $pageHashes[(int) $page->id] = $hash;
            $definitions[$hash] ??= ['catalog_title_id' => null];

            if ($definitions[$hash]['catalog_title_id'] === null
                && isset($catalogTitleIds[(int) $page->id])
            ) {
                $definitions[$hash]['catalog_title_id'] = $catalogTitleIds[(int) $page->id];
            }
        }

        return [
            'groups' => $definitions,
            'page_hashes' => $pageHashes,
        ];
    }

    private function groupHash(SourcePage $page): string
    {
        return hash(
            'sha256',
            $this->groupKeys->forUrl($page->url, $page->url_hash),
        );
    }

    /**
     * @param  list<string>  $hashes
     * @return array<int, true>
     */
    private function existingGroupIds(int $runId, array $hashes): array
    {
        return array_fill_keys(
            SeasonvarImportTitleGroup::query()
                ->where('seasonvar_import_run_id', $runId)
                ->whereIn('group_key_hash', $hashes)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all(),
            true,
        );
    }

    /**
     * @param  Collection<string, SeasonvarImportTitleGroup>  $groups
     * @param  array<string, array{catalog_title_id: int|null}>  $definitions
     */
    private function fillMissingCatalogTitles(
        Collection $groups,
        array $definitions,
    ): void {
        $assignments = [];

        foreach ($groups as $hash => $group) {
            $catalogTitleId = $definitions[$hash]['catalog_title_id'] ?? null;

            if ($group->catalog_title_id === null && is_int($catalogTitleId)) {
                $assignments[(int) $group->id] = $catalogTitleId;
            }
        }

        $this->caseUpdate(
            SeasonvarImportTitleGroup::class,
            'catalog_title_id',
            $assignments,
            'catalog_title_id IS NULL',
        );
    }

    /** @param array<int, int> $counts */
    private function incrementExpectedPages(array $counts): void
    {
        $this->caseUpdate(
            SeasonvarImportTitleGroup::class,
            'expected_pages',
            $counts,
            null,
            increment: true,
        );
    }

    /**
     * @param  class-string<SeasonvarImportTitleGroup>  $modelClass
     * @param  array<int, int>  $values
     */
    private function caseUpdate(
        string $modelClass,
        string $column,
        array $values,
        ?string $additionalPredicate,
        bool $increment = false,
    ): void {
        if ($values === []) {
            return;
        }

        $table = (new $modelClass)->getTable();
        $cases = [];
        $bindings = [];

        foreach ($values as $id => $value) {
            $cases[] = 'WHEN ? THEN ?';
            $bindings[] = $id;
            $bindings[] = $value;
        }

        $ids = array_keys($values);
        $expression = 'CASE id '.implode(' ', $cases).' ELSE 0 END';
        $assignment = $increment
            ? $column.' = '.$column.' + '.$expression
            : $column.' = '.$expression;
        $predicate = $additionalPredicate !== null
            ? ' AND '.$additionalPredicate
            : '';
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $bindings[] = now();
        array_push($bindings, ...$ids);

        DB::update(
            'UPDATE '.$table
            .' SET '.$assignment.', updated_at = ?'
            .' WHERE id IN ('.$placeholders.')'.$predicate,
            $bindings,
        );
    }

    /**
     * @param  list<int>  $preparedPageIds
     */
    private function dispatchPreparedPages(
        int $runId,
        array $preparedPageIds,
        string $queueName,
    ): int {
        $successfulIds = [];

        foreach ($preparedPageIds as $preparedPageId) {
            try {
                PrepareSeasonvarImportTitlePage::dispatch($preparedPageId)
                    ->onConnection((string) config(
                        'seasonvar.queue.connection',
                        'redis',
                    ))
                    ->onQueue($queueName)
                    ->afterCommit();
                $successfulIds[] = $preparedPageId;
            } catch (Throwable $exception) {
                Log::warning(
                    'Не удалось поставить подготовку страницы Seasonvar в очередь.',
                    [
                        'import_run_id' => $runId,
                        'prepared_page_id' => $preparedPageId,
                        'exception' => $exception::class,
                    ],
                );
            }
        }

        if ($successfulIds !== []) {
            SeasonvarImportPreparedPage::query()
                ->whereIn('id', $successfulIds)
                ->where('status', SeasonvarPreparedPageStatus::Queued->value)
                ->update([
                    'last_enqueue_attempt_at' => now(),
                    'enqueue_attempts' => DB::raw('enqueue_attempts + 1'),
                ]);
        }

        return count($successfulIds);
    }

    /**
     * @param  list<array{
     *     source_page_id: int,
     *     claim_token: string,
     *     group_key: string,
     *     force: bool
     * }>  $jobs
     */
    private function dispatchNonSerialPages(
        int $runId,
        array $jobs,
        string $queueName,
    ): int {
        $dispatched = 0;

        foreach ($jobs as $job) {
            try {
                ImportSeasonvarSourcePage::dispatch(
                    sourcePageId: $job['source_page_id'],
                    importRunId: $runId,
                    claimToken: $job['claim_token'],
                    groupKey: $job['group_key'],
                    force: $job['force'],
                )
                    ->onConnection((string) config(
                        'seasonvar.queue.connection',
                        'redis',
                    ))
                    ->onQueue($queueName)
                    ->afterCommit();
                $dispatched++;
            } catch (Throwable $exception) {
                Log::warning(
                    'Не удалось поставить страницу Seasonvar в очередь.',
                    [
                        'import_run_id' => $runId,
                        'source_page_id' => $job['source_page_id'],
                        'exception' => $exception::class,
                    ],
                );
            }
        }

        return $dispatched;
    }
}
