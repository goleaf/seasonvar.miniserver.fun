<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\DTOs\Operations\DeploymentCheck;
use App\Enums\CatalogSearchIndexStatus;
use App\Models\CatalogSearchIndexState;
use App\Models\SeasonvarImportRun;
use App\Services\Catalog\Search\CatalogSearchIndexer;
use App\Services\Seasonvar\SeasonvarImportProcessInspector;
use Closure;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class DeploymentReadinessChecker
{
    private const REQUIRED_INDEXES = [
        'catalog_titles_published_id_idx',
        'failed_jobs_connection_queue_failed_at_index',
        'licensed_media_home_feed_idx',
        'source_pages_refresh_import_retry_idx',
    ];

    public function __construct(
        private readonly Migrator $migrator,
        private readonly CatalogSearchIndexer $searchIndexer,
        private readonly FailedJobSummaryBuilder $failedJobs,
        private readonly SeasonvarImportProcessInspector $processInspector,
    ) {}

    /** @return list<DeploymentCheck> */
    public function check(): array
    {
        return [
            $this->timed(fn (): DeploymentCheck => $this->environmentCheck()),
            $this->timed(fn (): DeploymentCheck => $this->debugCheck()),
            $this->timed(fn (): DeploymentCheck => $this->loggingCheck()),
            $this->timed(fn (): DeploymentCheck => $this->migrationsCheck()),
            $this->timed(fn (): DeploymentCheck => $this->sqliteIntegrityCheck()),
            $this->timed(fn (): DeploymentCheck => $this->requiredIndexesCheck()),
            $this->timed(fn (): DeploymentCheck => $this->searchIndexCheck()),
            $this->timed(fn (): DeploymentCheck => $this->cacheTransportsCheck()),
            $this->timed(fn (): DeploymentCheck => $this->failedJobsCheck()),
            $this->timed(fn (): DeploymentCheck => $this->importerProcessCheck()),
        ];
    }

    /** @param Closure(): DeploymentCheck $check */
    private function timed(Closure $check): DeploymentCheck
    {
        $startedAt = hrtime(true);
        $result = $check();
        $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        return $result->withDuration($durationMs);
    }

    private function environmentCheck(): DeploymentCheck
    {
        $environment = (string) config('app.env');
        $safe = $environment === 'production';

        return new DeploymentCheck(
            'environment',
            $safe ? 'pass' : 'fail',
            $safe ? 'Приложение работает в production.' : 'APP_ENV должен быть production.',
            ['environment' => $environment],
        );
    }

    private function debugCheck(): DeploymentCheck
    {
        $debug = (bool) config('app.debug');

        return new DeploymentCheck(
            'debug',
            $debug ? 'fail' : 'pass',
            $debug ? 'APP_DEBUG должен быть выключен.' : 'Отладочный вывод выключен.',
            ['enabled' => $debug],
        );
    }

    private function loggingCheck(): DeploymentCheck
    {
        $default = (string) config('logging.default');
        $channels = $default === 'stack'
            ? array_values(array_filter((array) config('logging.channels.stack.channels')))
            : [$default];
        $safe = in_array('daily', $channels, true)
            && (string) config('logging.channels.daily.level', config('logging.level', 'debug')) === 'warning';

        return new DeploymentCheck(
            'logging',
            $safe ? 'pass' : 'fail',
            $safe ? 'Daily-журнал использует warning level.' : 'Нужен daily-журнал с warning level.',
            ['default' => $default, 'daily_enabled' => in_array('daily', $channels, true)],
        );
    }

    private function migrationsCheck(): DeploymentCheck
    {
        try {
            if (! $this->migrator->repositoryExists()) {
                return new DeploymentCheck('migrations', 'fail', 'Таблица истории миграций отсутствует.');
            }

            $files = $this->migrator->getMigrationFiles(database_path('migrations'));
            $ran = $this->migrator->getRepository()->getRan();
            $pending = array_values(array_diff(array_keys($files), $ran));

            return new DeploymentCheck(
                'migrations',
                $pending === [] ? 'pass' : 'fail',
                $pending === [] ? 'Все миграции применены.' : 'Есть неприменённые миграции.',
                ['pending_count' => count($pending)],
            );
        } catch (Throwable) {
            return new DeploymentCheck('migrations', 'fail', 'Не удалось проверить состояние миграций.');
        }
    }

    private function sqliteIntegrityCheck(): DeploymentCheck
    {
        if (DB::getDriverName() !== 'sqlite') {
            return new DeploymentCheck('sqlite_integrity', 'fail', 'Production source of truth должен оставаться SQLite.');
        }

        try {
            $quickCheck = DB::select('PRAGMA quick_check');
            $foreignKeyErrors = DB::select('PRAGMA foreign_key_check');
            $quickValue = strtolower((string) (array_values((array) ($quickCheck[0] ?? []))[0] ?? ''));
            $safe = $quickValue === 'ok' && $foreignKeyErrors === [];

            return new DeploymentCheck(
                'sqlite_integrity',
                $safe ? 'pass' : 'fail',
                $safe ? 'SQLite quick/FK checks пройдены.' : 'SQLite quick/FK checks обнаружили проблему.',
                ['foreign_key_errors' => count($foreignKeyErrors)],
            );
        } catch (Throwable) {
            return new DeploymentCheck('sqlite_integrity', 'fail', 'Не удалось выполнить SQLite integrity checks.');
        }
    }

    private function requiredIndexesCheck(): DeploymentCheck
    {
        try {
            $available = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'index'"))
                ->pluck('name')
                ->filter(fn (mixed $name): bool => is_string($name))
                ->all();
            $missing = array_values(array_diff(self::REQUIRED_INDEXES, $available));

            return new DeploymentCheck(
                'required_indexes',
                $missing === [] ? 'pass' : 'fail',
                $missing === [] ? 'Обязательные query indexes присутствуют.' : 'Отсутствуют обязательные query indexes.',
                ['missing_count' => count($missing)],
            );
        } catch (Throwable) {
            return new DeploymentCheck('required_indexes', 'fail', 'Не удалось проверить query indexes.');
        }
    }

    private function searchIndexCheck(): DeploymentCheck
    {
        try {
            if (! Schema::hasTable('catalog_title_search_documents') || ! Schema::hasTable('catalog_search_index_states')) {
                return new DeploymentCheck('search_index', 'fail', 'Таблицы поискового индекса отсутствуют.');
            }

            $sourceCount = $this->searchIndexer->sourceCount();
            $documentCount = $this->searchIndexer->documentCount();
            $ftsCount = (int) (DB::table('catalog_title_search_fts')->count());
            $state = CatalogSearchIndexState::query()->find(CatalogSearchIndexState::SINGLETON_ID);
            $readyState = $sourceCount === 0
                || ($state?->statusValue() === CatalogSearchIndexStatus::Ready
                    && (int) $state->version === CatalogSearchIndexer::INDEX_VERSION);
            $safe = $sourceCount === $documentCount
                && $documentCount === $ftsCount
                && $readyState;

            return new DeploymentCheck(
                'search_index',
                $safe ? 'pass' : 'fail',
                $safe ? 'FTS index согласован с публичным каталогом.' : 'FTS index требует rebuild или reconciliation.',
                [
                    'source_count' => $sourceCount,
                    'document_count' => $documentCount,
                    'fts_count' => $ftsCount,
                ],
            );
        } catch (Throwable) {
            return new DeploymentCheck('search_index', 'fail', 'Не удалось проверить FTS index.');
        }
    }

    private function cacheTransportsCheck(): DeploymentCheck
    {
        $configured = (string) config('cache.default') === 'redis-domain'
            && (string) config('cache.stores.redis-domain.driver') === 'redis'
            && (string) config('cache.stores.memcached-hot.driver') === 'memcached'
            && (string) config('session.driver') === 'redis'
            && (string) config('session.connection') === 'sessions'
            && (string) config('queue.default') === 'redis'
            && (string) config('queue.connections.redis.connection') === 'queues';

        return new DeploymentCheck(
            'cache_transports',
            $configured ? 'pass' : 'fail',
            $configured
                ? 'Cache/session/queue transports соответствуют production-профилю.'
                : 'Cache/session/queue transports не соответствуют production-профилю.',
        );
    }

    private function failedJobsCheck(): DeploymentCheck
    {
        try {
            $summary = $this->failedJobs->build();
            $hasFailures = $summary['total'] > 0;

            return new DeploymentCheck(
                'failed_jobs',
                $hasFailures ? 'warning' : 'pass',
                $hasFailures ? 'Есть failed jobs для ручной disposition.' : 'Failed jobs отсутствуют.',
                [
                    'total' => $summary['total'],
                    'job_kinds' => count($summary['jobs']),
                    'categories' => count($summary['categories']),
                    'age_buckets' => count($summary['ages']),
                    'reason_buckets' => count($summary['reasons']),
                ],
            );
        } catch (Throwable) {
            return new DeploymentCheck('failed_jobs', 'fail', 'Не удалось безопасно получить сводку failed jobs.');
        }
    }

    private function importerProcessCheck(): DeploymentCheck
    {
        try {
            $runs = SeasonvarImportRun::query()
                ->where('status', 'running')
                ->where('execution_mode', 'sync')
                ->latest('updated_at')
                ->get();
            $lockProcess = app()->environment('testing')
                ? null
                : Cache::store((string) config('seasonvar.queue.lock_store', 'redis-locks'))
                    ->get('seasonvar-import-process');
            $inspection = $this->processInspector->inspect(
                is_array($lockProcess) ? $lockProcess : null,
                $runs,
            );

            return new DeploymentCheck(
                'importer_process',
                $inspection['running'] ? 'pass' : 'warning',
                $inspection['running']
                    ? 'Подтверждён один активный importer process.'
                    : 'Активный importer process не подтверждён.',
                [
                    'running' => $inspection['running'],
                    'verified' => $inspection['verified'],
                    'pid' => $inspection['pid'],
                ],
            );
        } catch (Throwable) {
            return new DeploymentCheck('importer_process', 'warning', 'Importer process profile недоступен для проверки.');
        }
    }
}
